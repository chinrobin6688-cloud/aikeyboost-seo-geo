<?php
namespace AIKEY\SeoGeo\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Secure image importer for the Article Manager rich-text paste workflow.
 *
 * Images are imported only from authenticated wp-admin AJAX requests. Clipboard
 * blobs are uploaded through WordPress' native media pipeline; remote images are
 * fetched with wp_safe_remote_get() and strict type/size/dimension limits before
 * they are sideloaded into the Media Library.
 */
final class Article_Image_Importer {
    public const AJAX_ACTION = 'aikey_article_import_image';
    public const NONCE_ACTION = 'aikey_article_import_image';
    public const MAX_PER_PASTE = 30;
    public const MAX_PER_SESSION = 60;

    private const MAX_BYTES = 8388608; // 8 MiB; the site's own upload limit may be lower.
    private const MAX_DIMENSION = 12000;
    private const MAX_PIXELS = 24000000;
    private const PENDING_USER_META = '_aikey_article_import_pending_user';

    /** @var array<string,string> */
    private const ALLOWED_MIMES = array(
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png'          => 'image/png',
        'webp'         => 'image/webp',
        'gif'          => 'image/gif',
    );

    public static function register_hooks(): void {
        add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'ajax_import' ) );
    }

    public static function max_bytes(): int {
        $limit = self::MAX_BYTES;
        if ( function_exists( 'wp_max_upload_size' ) ) {
            $site_limit = (int) wp_max_upload_size();
            if ( $site_limit > 0 ) { $limit = min( $limit, $site_limit ); }
        }
        return max( 1, $limit );
    }

    public static function ajax_import(): void {
        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_posts' ) || ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( array( 'message' => '权限不足，无法导入图片。' ), 403 );
        }
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( $post_id && ( 'post' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) ) {
            wp_send_json_error( array( 'message' => '无法把图片关联到该文章。' ), 403 );
        }

        $alt = isset( $_POST['alt'] ) && is_scalar( $_POST['alt'] )
            ? self::limit_text( sanitize_text_field( wp_unslash( (string) $_POST['alt'] ) ), 200 )
            : '';

        try {
            if ( isset( $_FILES['image'] ) && is_array( $_FILES['image'] ) ) {
                $result = self::import_uploaded_image( $_FILES['image'], $post_id, $alt );
            } else {
                $source_url = isset( $_POST['source_url'] ) && is_scalar( $_POST['source_url'] )
                    ? trim( wp_unslash( (string) $_POST['source_url'] ) )
                    : '';
                $result = self::import_remote_image( $source_url, $post_id, $alt );
            }
        } catch ( \Throwable $error ) {
            $result = new \WP_Error( 'article_image_import_runtime', '图片导入过程中出现运行异常，请改用“添加媒体”上传。' );
        }

        if ( is_wp_error( $result ) ) {
            $error_data = $result->get_error_data();
            $status = is_array( $error_data ) && isset( $error_data['http_status'] ) ? (int) $error_data['http_status'] : 0;
            wp_send_json_error(
                array( 'message' => sanitize_text_field( $result->get_error_message() ) ),
                $status >= 400 && $status <= 599 ? $status : 422
            );
        }

        wp_send_json_success( $result );
    }

    /**
     * Attach images pasted before a new article had a post ID.
     *
     * @param string $raw_ids Comma-separated attachment IDs returned by AJAX.
     */
    public static function attach_pending_to_post( int $post_id, string $raw_ids ): void {
        if ( $post_id <= 0 || 'post' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) { return; }

        $ids = preg_split( '/[^0-9]+/', $raw_ids, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $ids ) ) { return; }
        $ids = array_slice( array_values( array_unique( array_map( 'absint', $ids ) ) ), 0, self::MAX_PER_SESSION );
        $user_id = get_current_user_id();

        foreach ( $ids as $attachment_id ) {
            if ( $attachment_id <= 0 ) { continue; }
            $attachment = get_post( $attachment_id );
            if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) { continue; }
            if ( 0 !== strpos( (string) $attachment->post_mime_type, 'image/' ) ) { continue; }

            $pending_user = (int) get_post_meta( $attachment_id, self::PENDING_USER_META, true );
            if ( $pending_user !== $user_id ) { continue; }

            if ( 0 === (int) $attachment->post_parent ) {
                wp_update_post( array( 'ID' => $attachment_id, 'post_parent' => $post_id ) );
            }
            delete_post_meta( $attachment_id, self::PENDING_USER_META );
        }
    }

    /** @param array<string,mixed> $file */
    private static function import_uploaded_image( array $file, int $post_id, string $alt ) {
        $error = isset( $file['error'] ) && is_scalar( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ( UPLOAD_ERR_OK !== $error ) {
            return new \WP_Error( 'article_image_upload_error', '剪贴板图片上传失败，请重试或使用“添加媒体”。', array( 'http_status' => 400 ) );
        }

        $tmp_name = isset( $file['tmp_name'] ) && is_scalar( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
        $name = isset( $file['name'] ) && is_scalar( $file['name'] ) ? (string) $file['name'] : '';
        $validated = self::validate_image_file( $tmp_name, $name );
        if ( is_wp_error( $validated ) ) { return $validated; }

        self::load_media_dependencies();
        $_FILES['image']['name'] = self::safe_filename( $name, (string) $validated['mime'] );
        $attachment_id = media_handle_upload(
            'image',
            $post_id,
            array(),
            array( 'test_form' => false, 'mimes' => self::ALLOWED_MIMES )
        );
        if ( is_wp_error( $attachment_id ) ) { return $attachment_id; }
        return self::finalize_attachment( (int) $attachment_id, $post_id, $alt, true );
    }

    private static function import_remote_image( string $source_url, int $post_id, string $alt ) {
        if ( '' === $source_url || strlen( $source_url ) > 2048 ) {
            return new \WP_Error( 'article_image_url_missing', '外部图片地址无效。', array( 'http_status' => 400 ) );
        }

        $source_url = esc_url_raw( $source_url, array( 'http', 'https' ) );
        if ( ! self::is_safe_remote_url( $source_url ) ) {
            return new \WP_Error( 'article_image_url_unsafe', '该外部图片地址不允许由服务器访问。', array( 'http_status' => 400 ) );
        }

        $existing_id = function_exists( 'attachment_url_to_postid' ) ? (int) attachment_url_to_postid( $source_url ) : 0;
        if ( $existing_id > 0 && self::attachment_is_allowed_image( $existing_id ) ) {
            return self::finalize_attachment( $existing_id, $post_id, $alt, false );
        }

        self::load_media_dependencies();
        $tmp = wp_tempnam( 'aikey-article-image' );
        if ( ! is_string( $tmp ) || '' === $tmp ) {
            return new \WP_Error( 'article_image_temp_failed', '无法创建图片临时文件。' );
        }

        try {
            $response = wp_safe_remote_get(
                $source_url,
                array(
                    'timeout'             => 10,
                    'redirection'         => 3,
                    'stream'              => true,
                    'filename'            => $tmp,
                    'limit_response_size' => self::max_bytes() + 1,
                    'reject_unsafe_urls'  => true,
                    'headers'             => array( 'Accept' => 'image/webp,image/png,image/jpeg,image/gif,*/*;q=0.5' ),
                )
            );
            if ( is_wp_error( $response ) ) {
                return new \WP_Error( 'article_image_remote_failed', '无法安全下载外部图片，请保留原图或使用“添加媒体”手动上传。' );
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( 200 !== $code ) {
                return new \WP_Error( 'article_image_remote_status', '外部图片服务器没有返回可下载的图片。' );
            }

            $filename = self::filename_from_url( $source_url );
            $validated = self::validate_image_file( $tmp, $filename );
            if ( is_wp_error( $validated ) ) { return $validated; }

            $filename = self::safe_filename( $filename, (string) $validated['mime'] );
            $attachment_id = media_handle_sideload(
                array(
                    'name'     => $filename,
                    'tmp_name' => $tmp,
                    'error'    => 0,
                    'size'     => (int) filesize( $tmp ),
                ),
                $post_id
            );
            // media_handle_sideload() owns/removes the temporary file on success.
            if ( is_wp_error( $attachment_id ) ) { return $attachment_id; }
            $tmp = '';
            return self::finalize_attachment( (int) $attachment_id, $post_id, $alt, true );
        } finally {
            if ( '' !== $tmp && is_file( $tmp ) ) { wp_delete_file( $tmp ); }
        }
    }

    private static function is_safe_remote_url( string $url ): bool {
        if ( '' === $url ) { return false; }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) { return false; }
        $scheme = strtolower( (string) $parts['scheme'] );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) { return false; }
        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) { return false; }

        $host = rtrim( strtolower( trim( (string) $parts['host'], '[]' ) ), '.' );
        if ( '' === $host || 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' ) ) {
            return false;
        }
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            $public_ip = filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
            if ( false === $public_ip ) { return false; }
        }
        // WordPress 6.2+ always provides wp_http_validate_url(); fail closed if
        // an unusual bootstrap omits it rather than silently weakening SSRF checks.
        return function_exists( 'wp_http_validate_url' ) && (bool) wp_http_validate_url( $url );
    }

    private static function validate_image_file( string $path, string $name ) {
        if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
            return new \WP_Error( 'article_image_file_missing', '图片临时文件不可读取。', array( 'http_status' => 400 ) );
        }
        $size = (int) filesize( $path );
        if ( $size <= 0 || $size > self::max_bytes() ) {
            return new \WP_Error( 'article_image_file_too_large', '图片文件超过允许大小，或文件为空。', array( 'http_status' => 413 ) );
        }

        $mime = function_exists( 'wp_get_image_mime' ) ? (string) wp_get_image_mime( $path ) : '';
        if ( '' === $mime && function_exists( 'mime_content_type' ) ) { $mime = (string) mime_content_type( $path ); }
        if ( ! in_array( $mime, array_values( self::ALLOWED_MIMES ), true ) ) {
            return new \WP_Error( 'article_image_type_not_allowed', '仅允许 JPG、PNG、WebP、GIF 图片。', array( 'http_status' => 415 ) );
        }

        $dimensions = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $path ) : @getimagesize( $path );
        if ( ! is_array( $dimensions ) || empty( $dimensions[0] ) || empty( $dimensions[1] ) ) {
            return new \WP_Error( 'article_image_invalid_pixels', '文件不是可解析的有效图片。', array( 'http_status' => 415 ) );
        }
        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];
        if ( $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || ( $width * $height ) > self::MAX_PIXELS ) {
            return new \WP_Error( 'article_image_dimensions_too_large', '图片像素尺寸过大，请压缩后再粘贴。', array( 'http_status' => 413 ) );
        }

        return array( 'mime' => $mime, 'width' => $width, 'height' => $height, 'name' => $name );
    }

    private static function safe_filename( string $name, string $mime ): string {
        $extension_map = array(
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        );
        $ext = $extension_map[ $mime ] ?? 'jpg';
        $name = sanitize_file_name( wp_basename( $name ) );
        $base = preg_replace( '/\.[^.]+$/', '', $name ) ?? '';
        // Do not preserve nested/double extensions such as image.php.jpg.
        $base = str_replace( '.', '-', $base );
        $base = trim( sanitize_file_name( $base ), '.-_' );
        if ( '' === $base ) { $base = 'article-image-' . gmdate( 'Ymd-His' ) . '-' . wp_rand( 1000, 9999 ); }
        return $base . '.' . $ext;
    }

    private static function filename_from_url( string $url ): string {
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $name = rawurldecode( wp_basename( $path ) );
        return '' !== trim( $name ) ? $name : 'article-image';
    }

    private static function finalize_attachment( int $attachment_id, int $post_id, string $alt, bool $created_now ) {
        if ( ! self::attachment_is_allowed_image( $attachment_id ) ) {
            if ( $created_now && function_exists( 'wp_delete_attachment' ) ) { wp_delete_attachment( $attachment_id, true ); }
            return new \WP_Error( 'article_image_attachment_invalid', '媒体库没有生成有效图片附件。' );
        }

        if ( '' !== $alt ) { update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt ); }
        if ( 0 === $post_id && $created_now ) {
            update_post_meta( $attachment_id, self::PENDING_USER_META, get_current_user_id() );
        }

        $url = (string) wp_get_attachment_url( $attachment_id );
        if ( '' === $url ) {
            if ( $created_now && function_exists( 'wp_delete_attachment' ) ) { wp_delete_attachment( $attachment_id, true ); }
            return new \WP_Error( 'article_image_attachment_url_missing', '媒体库图片地址生成失败。' );
        }

        $mime = (string) get_post_mime_type( $attachment_id );
        $meta = wp_get_attachment_metadata( $attachment_id );
        return array(
            'attachmentId' => $attachment_id,
            'url'          => esc_url_raw( $url ),
            'alt'          => $alt,
            'mime'         => $mime,
            'width'        => is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0,
            'height'       => is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : 0,
        );
    }

    private static function attachment_is_allowed_image( int $attachment_id ): bool {
        if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) { return false; }
        return in_array( (string) get_post_mime_type( $attachment_id ), array_values( self::ALLOWED_MIMES ), true );
    }

    private static function load_media_dependencies(): void {
        $admin_dir = ABSPATH . 'wp-admin/includes/';
        if ( ! function_exists( 'media_handle_upload' ) || ! function_exists( 'media_handle_sideload' ) ) {
            require_once $admin_dir . 'file.php';
            require_once $admin_dir . 'media.php';
            require_once $admin_dir . 'image.php';
        }
    }

    private static function limit_text( string $value, int $max ): string {
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max, 'UTF-8' ) : substr( $value, 0, $max );
    }
}
