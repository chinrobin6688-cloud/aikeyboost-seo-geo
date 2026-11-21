<?php
namespace AIKEY\SeoGeo\SEO\Sitemap;

use AIKEY\SeoGeo\Integrations\China_Engine_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Manage the single physical robots.txt used by the public site.
 *
 * The target path is fixed by the WordPress/site document root. No user input
 * can influence the path. Writes use a same-directory temporary file and an
 * atomic rename so a failed write does not truncate the existing robots.txt.
 */
final class Robots_File_Manager {
    private const MAX_BYTES = 65536;
    private const LEGACY_OPTION = 'aikey_seo_geo_protocol_overrides';
    private const MANAGED_BEGIN = '# BEGIN AIQIBANG SEO&GEO CRAWLER POLICY';
    private const MANAGED_END = '# END AIQIBANG SEO&GEO CRAWLER POLICY';

    public static function path(): string {
        $absolute = rtrim( (string) ABSPATH, '/\\' );
        $real_absolute = realpath( $absolute );
        $document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? trim( (string) $_SERVER['DOCUMENT_ROOT'] ) : '';
        $home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

        // For the common "WordPress in a subdirectory, public site at /" setup,
        // robots.txt belongs in the web document root. Only trust DOCUMENT_ROOT
        // when it is the WordPress root or a real ancestor of ABSPATH.
        if ( '' !== $document_root && ( '' === $home_path || '/' === $home_path ) ) {
            $real_document_root = realpath( $document_root );
            $absolute_inside_document_root = false;
            if ( false !== $real_document_root && false !== $real_absolute ) {
                $prefix = rtrim( $real_document_root, '/\\' ) . DIRECTORY_SEPARATOR;
                $absolute_inside_document_root = $real_absolute === $real_document_root || 0 === strpos( $real_absolute . DIRECTORY_SEPARATOR, $prefix );
            }
            if ( $absolute_inside_document_root && is_dir( $real_document_root ) ) {
                return rtrim( $real_document_root, '/\\' ) . DIRECTORY_SEPARATOR . 'robots.txt';
            }
        }

        return $absolute . DIRECTORY_SEPARATOR . 'robots.txt';
    }

    public static function exists(): bool {
        $path = self::path();
        return ! is_link( $path ) && is_file( $path );
    }

    public static function is_symlink(): bool {
        return is_link( self::path() );
    }

    public static function writable(): bool {
        $path = self::path();
        $dir = dirname( $path );
        if ( is_link( $path ) ) { return false; }
        if ( file_exists( $path ) ) { return is_file( $path ) && wp_is_writable( $path ) && wp_is_writable( $dir ); }
        return is_dir( $dir ) && wp_is_writable( $dir );
    }

    /** @return string|\WP_Error */
    public static function read() {
        $path = self::path();
        if ( is_link( $path ) ) {
            return new \WP_Error( 'robots_symlink', '检测到 robots.txt 为符号链接。为避免写入到未知目标，插件不会读取或修改该文件。' );
        }
        if ( ! is_file( $path ) ) {
            return new \WP_Error( 'robots_missing', '网站根目录尚未创建物理 robots.txt。' );
        }
        if ( ! is_readable( $path ) ) {
            return new \WP_Error( 'robots_unreadable', '物理 robots.txt 当前不可读取，请检查服务器文件权限。' );
        }
        $size = @filesize( $path );
        if ( false !== $size && $size > self::MAX_BYTES ) {
            return new \WP_Error( 'robots_too_large', '物理 robots.txt 超过 64 KB，插件不会直接加载该文件。' );
        }
        $content = @file_get_contents( $path );
        if ( false === $content ) {
            return new \WP_Error( 'robots_read_failed', '读取物理 robots.txt 失败，请检查服务器文件权限。' );
        }
        return self::normalize_text( $content );
    }

    /**
     * Content shown in the editor. Existing physical content is authoritative.
     * A legacy v2.0.3-v2.0.5 manual robots value is used only as a one-time draft
     * when no physical file exists; otherwise a safe China SEO/GEO default is used.
     */
    public static function editor_content(): string {
        $current = self::read();
        if ( ! is_wp_error( $current ) ) { return (string) $current; }

        $legacy = get_option( self::LEGACY_OPTION, array() );
        if ( is_array( $legacy ) && ! empty( $legacy['robots_content'] ) && is_string( $legacy['robots_content'] ) ) {
            $candidate = self::validate( $legacy['robots_content'] );
            if ( ! is_wp_error( $candidate ) ) {
                return self::prepare_for_write( (string) $candidate );
            }
        }
        return self::recommended_content();
    }

    public static function recommended_content(): string {
        $base = "# AI企帮SEO&GEO - China SEO/GEO crawler friendly\n"
            . "User-agent: *\n"
            . "Allow: /\n"
            . "Disallow: /wp-admin/\n"
            . "Allow: /wp-admin/admin-ajax.php\n";
        if ( Sitemap_Module::is_active() ) {
            $base .= "\nSitemap: " . Sitemap_Module::main_url() . "\n";
        }
        return self::apply_managed_block( $base );
    }

    /** @return bool|\WP_Error */
    public static function save( string $content ) {
        $validated = self::validate( $content );
        if ( is_wp_error( $validated ) ) { return $validated; }
        $content = self::prepare_for_write( (string) $validated );
        if ( strlen( $content ) > self::MAX_BYTES ) {
            return new \WP_Error( 'robots_too_large_after_rules', '补充 Sitemap/crawler 管理规则后 robots.txt 超过 64 KB，请精简自定义内容。' );
        }

        $path = self::path();
        $dir = dirname( $path );
        if ( is_link( $path ) ) {
            return new \WP_Error( 'robots_symlink', '检测到 robots.txt 为符号链接。为避免写入未知目标，插件拒绝修改。' );
        }
        if ( file_exists( $path ) && ! is_file( $path ) ) {
            return new \WP_Error( 'robots_not_file', 'robots.txt 路径已被非普通文件占用，插件拒绝修改。' );
        }
        if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
            return new \WP_Error( 'robots_dir_not_writable', '网站根目录不可写，无法安全创建或更新物理 robots.txt。请检查 PHP/站点目录权限。' );
        }
        if ( is_file( $path ) && ! wp_is_writable( $path ) ) {
            return new \WP_Error( 'robots_not_writable', '物理 robots.txt 当前不可写，请检查文件所有者和权限。' );
        }

        $tmp = @tempnam( $dir, '.aikey-robots-' );
        if ( false === $tmp ) {
            return new \WP_Error( 'robots_temp_failed', '无法在网站根目录创建安全临时文件，robots.txt 未修改。' );
        }

        $real_dir = realpath( $dir );
        $real_tmp_dir = realpath( dirname( $tmp ) );
        if ( false === $real_dir || false === $real_tmp_dir || $real_dir !== $real_tmp_dir ) {
            wp_delete_file( $tmp );
            return new \WP_Error( 'robots_temp_location', '临时文件未创建在网站根目录，已安全中止写入。' );
        }

        $bytes = @file_put_contents( $tmp, $content, LOCK_EX );
        if ( false === $bytes || strlen( $content ) !== (int) $bytes ) {
            wp_delete_file( $tmp );
            return new \WP_Error( 'robots_write_failed', '写入临时 robots.txt 失败，原文件保持不变。' );
        }

        $mode = is_file( $path ) ? ( @fileperms( $path ) & 0777 ) : 0644;
        if ( $mode <= 0 ) { $mode = 0644; }
        // Preserve robots.txt permissions when the temporary file replaces it.
        // This operation must stay on the same local filesystem as the temp file.
        if ( ! @chmod( $tmp, $mode ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- native chmod preserves the replaced file's mode.
            wp_delete_file( $tmp );
            return new \WP_Error( 'robots_chmod_failed', '无法设置 robots.txt 临时文件权限，原文件保持不变。' );
        }

        // The local same-directory rename preserves atomic replacement. WordPress
        // WP_Filesystem_Direct::move() deletes an existing target before renaming;
        // that would create a window without robots.txt, so it is unsuitable here.
        if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- explicitly required for atomic same-directory overwrite.
            wp_delete_file( $tmp );
            return new \WP_Error( 'robots_replace_failed', '无法原子替换物理 robots.txt，原文件保持不变。请检查目录权限。' );
        }

        clearstatcache( true, $path );
        $verify = self::read();
        if ( is_wp_error( $verify ) || self::normalize_for_compare( (string) $verify ) !== self::normalize_for_compare( $content ) ) {
            return new \WP_Error( 'robots_verify_failed', 'robots.txt 已写入，但回读校验失败。请立即检查服务器文件权限或安全软件拦截。' );
        }
        return true;
    }

    /**
     * Refresh only the plugin-managed crawler-policy block after engine settings
     * change. User-written rules outside the block remain untouched.
     *
     * @return bool|\WP_Error
     */
    public static function sync_managed_block() {
        if ( ! self::exists() ) { return true; }
        $content = self::read();
        if ( is_wp_error( $content ) ) { return $content; }
        return self::save( (string) $content );
    }

    /** @return string|\WP_Error */
    public static function validate( string $content ) {
        $content = trim( self::normalize_text( $content ) );
        if ( '' === $content ) {
            return new \WP_Error( 'robots_empty', 'robots.txt 内容不能为空。' );
        }
        if ( strlen( $content ) > self::MAX_BYTES ) {
            return new \WP_Error( 'robots_too_large', 'robots.txt 内容不能超过 64 KB。' );
        }
        if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content ) ) {
            return new \WP_Error( 'robots_control_chars', 'robots.txt 包含不允许的控制字符。' );
        }
        return $content . "\n";
    }

    public static function normalize_for_compare( string $content ): string {
        return trim( self::normalize_text( $content ) );
    }

    public static function contains_required_sitemaps( string $content ): bool {
        $url = Sitemap_Module::main_url();
        return 1 === preg_match( '/^[\t ]*Sitemap[\t ]*:[\t ]*' . preg_quote( $url, '/' ) . '[\t ]*$/mi', $content );
    }

    public static function blocks_all_crawlers( string $content ): bool {
        $normalized = self::normalize_text( $content );
        $groups = preg_split( '/(?=^User-agent\s*:)/mi', $normalized );
        foreach ( is_array( $groups ) ? $groups : array() as $group ) {
            if ( ! preg_match( '/^User-agent\s*:\s*\*\s*$/mi', $group ) ) { continue; }
            if ( preg_match( '/^Disallow\s*:\s*\/\s*$/mi', $group ) ) { return true; }
        }
        return false;
    }

    private static function prepare_for_write( string $content ): string {
        $content = self::strip_managed_block( self::normalize_text( $content ) );
        $content = self::ensure_sitemaps( trim( $content ) );
        $content = self::apply_managed_block( $content );
        return rtrim( $content ) . "\n";
    }

    private static function ensure_sitemaps( string $content ): string {
        // If our Sitemap is inactive because another SEO/Sitemap plugin owns the
        // protocol, preserve that plugin's Sitemap declarations exactly.
        if ( ! Sitemap_Module::is_active() ) { return rtrim( $content ); }
        $content = Sitemap_Module::strip_managed_sitemap_lines( $content );
        return rtrim( $content ) . "\nSitemap: " . Sitemap_Module::main_url();
    }

    private static function apply_managed_block( string $content ): string {
        $bots = China_Engine_Module::blocked_bots();
        if ( ! $bots ) { return rtrim( $content ) . "\n"; }
        $block = self::MANAGED_BEGIN . "\n";
        foreach ( $bots as $bot ) {
            $block .= 'User-agent: ' . $bot . "\nDisallow: /\n";
        }
        $block .= self::MANAGED_END;
        return rtrim( $content ) . "\n\n" . $block . "\n";
    }

    private static function strip_managed_block( string $content ): string {
        $pattern = '/^[\t ]*' . preg_quote( self::MANAGED_BEGIN, '/' ) . '[\t ]*$.*?^[\t ]*' . preg_quote( self::MANAGED_END, '/' ) . '[\t ]*$/ms';
        $clean = preg_replace( $pattern, '', $content );
        return is_string( $clean ) ? trim( $clean ) : trim( $content );
    }

    private static function normalize_text( string $value ): string {
        $value = preg_replace( '/^\xEF\xBB\xBF/', '', $value );
        $value = is_string( $value ) ? $value : '';
        return str_replace( array( "\r\n", "\r" ), "\n", $value );
    }
}
