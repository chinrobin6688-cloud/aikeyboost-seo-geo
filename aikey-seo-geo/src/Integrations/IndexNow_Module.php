<?php
namespace AIKEY\SeoGeo\Integrations;

use AIKEY\SeoGeo\Core\Conflict_Detector;
use AIKEY\SeoGeo\Core\Module_Interface;
use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\Support\Utils;
use AIKEY\SeoGeo\SEO\Sitemap\Sitemap_Content;

defined( 'ABSPATH' ) || exit;

final class IndexNow_Module implements Module_Interface {
    public const LAST_SIG = '_aikey_indexnow_last_submit_sig';

    /** @var array<int,string> */
    private array $pending_posts = array();
    private ?Sitemap_Content $indexable_content = null;

    public function id(): string { return 'indexnow'; }

    public function register(): void {
        add_action( 'init', array( $this, 'rewrite' ) );
        add_filter( 'query_vars', array( $this, 'query_vars' ) );
        add_action( 'template_redirect', array( $this, 'serve_key' ), 0 );
        if ( Settings::section_get( 'indexnow', 'auto_submit', false ) ) {
            add_action( 'transition_post_status', array( $this, 'on_status_change' ), 20, 3 );
            add_action( 'post_updated', array( $this, 'on_updated' ), 20, 3 );
            add_action( 'before_delete_post', array( $this, 'on_delete' ), 20, 2 );
            // Defer publish/update submission until all REST/meta-box fields have
            // been saved. This prevents submitting a URL the editor just marked noindex.
            add_action( 'shutdown', array( $this, 'flush_pending' ), 20 );
        }
    }

    public function rewrite(): void {
        add_rewrite_rule( '^aikey-indexnow-key\.txt$', 'index.php?aikey_indexnow_key=1', 'top' );
    }

    public function query_vars( array $vars ): array { $vars[] = 'aikey_indexnow_key'; return $vars; }

    public function serve_key(): void {
        if ( '1' !== (string) get_query_var( 'aikey_indexnow_key' ) ) { return; }
        nocache_headers();
        header( 'Content-Type: text/plain; charset=utf-8' );
        $key = self::key();
        if ( '' === $key ) {
            status_header( 503 );
            echo 'IndexNow key is temporarily unavailable.';
            exit;
        }
        echo esc_html( $key );
        exit;
    }

    public function on_status_change( string $new, string $old, \WP_Post $post ): void {
        if ( ! $this->eligible( $post, false ) ) { return; }
        if ( 'publish' === $new && 'publish' !== $old ) {
            $this->pending_posts[ $post->ID ] = 'publish';
        }
    }

    public function on_updated( int $post_id, \WP_Post $after, \WP_Post $before ): void {
        if ( 'publish' !== $after->post_status || 'publish' !== $before->post_status || ! $this->eligible( $after, false ) ) { return; }
        if ( $after->post_modified_gmt === $before->post_modified_gmt ) { return; }
        $this->pending_posts[ $post_id ] = 'update';
    }

    public function flush_pending(): void {
        if ( empty( $this->pending_posts ) ) { return; }
        $pending = $this->pending_posts;
        $this->pending_posts = array();
        foreach ( $pending as $post_id => $event ) {
            $post = get_post( (int) $post_id );
            if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! $this->eligible( $post, true ) ) { continue; }
            $this->submit_post( $post, $event );
        }
    }

    public function on_delete( int $post_id, \WP_Post $post ): void {
        if ( 'publish' !== $post->post_status || ! $this->eligible( $post, false ) ) { return; }
        $url = get_permalink( $post );
        if ( $url ) { self::submit_urls( array( $url ), 'delete' ); }
    }

    private function submit_post( \WP_Post $post, string $event ): void {
        $sig = Daily_Submission::post_signature( $post );
        if ( '' !== $sig && $sig === (string) get_post_meta( $post->ID, self::LAST_SIG, true ) ) { return; }
        $url = get_permalink( $post );
        if ( ! $url ) { return; }
        $result = self::submit_urls( array( $url ), $event );
        if ( ! is_wp_error( $result ) && ! empty( $result['success'] ) ) {
            update_post_meta( $post->ID, self::LAST_SIG, $sig );
        }
    }

    private function eligible( \WP_Post $post, bool $check_index = true ): bool {
        if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) { return false; }
        if ( ! in_array( $post->post_type, Utils::public_post_types(), true ) ) { return false; }
        if ( ! $check_index ) { return true; }
        $this->indexable_content ??= new Sitemap_Content();
        return '' !== $this->indexable_content->indexable_post_url( $post );
    }

    public static function key(): string {
        $settings = Settings::all();
        $key = sanitize_key( (string) ( $settings['indexnow']['key'] ?? '' ) );
        if ( strlen( $key ) >= 8 ) { return $key; }
        $key = strtolower( wp_generate_password( 32, false, false ) );
        $settings['indexnow']['key'] = $key;
        if ( ! Settings::update( $settings ) ) {
            // Never submit a transient key that the public key endpoint cannot
            // reproduce on the next request.
            return '';
        }
        return $key;
    }

    public static function key_location(): string { return home_url( '/aikey-indexnow-key.txt' ); }

    public static function submit_urls( array $urls, string $event = 'manual' ): array|\WP_Error {
        if ( ! Settings::module_enabled( 'indexnow' ) ) { return new \WP_Error( 'indexnow_disabled', 'IndexNow 模块已关闭。' ); }
        if ( ! Conflict_Detector::frontend_allowed() ) { return new \WP_Error( 'conflict', '前台 SEO 接管未启用，暂不执行 IndexNow 提交。' ); }
        if ( ! (bool) get_option( 'blog_public', true ) ) { return new \WP_Error( 'site_private', '站点当前设置为“建议搜索引擎不索引”，已停止 IndexNow 提交。' ); }
        $host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        if ( '' === $host ) { return new \WP_Error( 'invalid_host', '无法识别当前站点域名。' ); }
        $urls = array_values( array_unique( array_filter( array_map( static function( $url ) use ( $host ): string {
            $clean = esc_url_raw( (string) $url, array( 'http','https' ) );
            return $clean && strtolower( (string) wp_parse_url( $clean, PHP_URL_HOST ) ) === strtolower( $host ) ? $clean : '';
        }, $urls ) ) ) );
        if ( ! $urls ) { return new \WP_Error( 'empty_urls', '没有可提交的站内 URL。' ); }
        $urls = array_slice( $urls, 0, 10000 );
        $key = self::key();
        if ( '' === $key ) {
            return new \WP_Error( 'indexnow_key_store_failed', 'IndexNow Key 无法安全写入数据库，本次提交已取消。' );
        }
        $response = wp_safe_remote_post( 'https://api.indexnow.org/indexnow', array(
            'timeout' => 20,
            'redirection' => 2,
            'headers' => array( 'Content-Type'=>'application/json; charset=utf-8' ),
            'body' => wp_json_encode( array(
                'host' => $host,
                'key' => $key,
                'keyLocation' => self::key_location(),
                'urlList' => $urls,
            ), JSON_UNESCAPED_SLASHES ),
        ) );
        if ( is_wp_error( $response ) ) {
            Submission_Log::add( 'indexnow', count( $urls ), 0, false, $response->get_error_message(), $urls[0] ?? '' );
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( ! in_array( $code, array( 200, 202 ), true ) ) {
            Submission_Log::add( 'indexnow', count( $urls ), $code, false, 'IndexNow 提交失败：' . sanitize_key( $event ), $urls[0] ?? '' );
            return new \WP_Error( 'indexnow_error', 'IndexNow 提交失败，HTTP ' . $code . '。', array( 'status'=>$code ) );
        }
        Submission_Log::add( 'indexnow', count( $urls ), $code, true, 'IndexNow：' . sanitize_key( $event ), $urls[0] ?? '' );
        return array( 'success'=>count( $urls ), 'status'=>$code );
    }

    public static function recent_urls( int $limit = 100 ): array {
        $limit = min( 1000, max( 1, $limit ) );
        $posts = get_posts( array(
            'post_type'=>Utils::public_post_types(), 'post_status'=>'publish', 'posts_per_page'=>$limit,
            'orderby'=>'modified', 'order'=>'DESC', 'fields'=>'ids', 'no_found_rows'=>true,
        ) );
        $urls = array();
        foreach ( $posts as $post_id ) {
            if ( 'noindex' === \AIKEY\SeoGeo\SEO\Meta\Meta_Repository::robots_index( (int) $post_id ) ) { continue; }
            $url = get_permalink( $post_id ); if ( $url ) { $urls[] = $url; }
        }
        return $urls;
    }
}
