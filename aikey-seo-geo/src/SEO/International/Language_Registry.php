<?php
namespace AIKEY\SeoGeo\SEO\International;

use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\GEO\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Site-local language registry. Only explicitly registered languages become
 * eligible translation targets. No hardcoded finite language list.
 */
final class Language_Registry {
    public static function all(): array {
        $cfg = Settings::section( 'international' );
        $rows = $cfg['languages'] ?? array();
        if ( is_array( $rows ) && $rows ) { return $rows; }
        $default = Language::site_code();
        return array( $default => array(
            'name'=>$default, 'home_id'=>(int) get_option( 'page_on_front', 0 ), 'enabled'=>true,
        ) );
    }

    public static function default_code(): string {
        $code = Language::normalize( (string) Settings::section_get( 'international', 'default_locale', '' ) );
        $languages = self::all();
        if ( '' !== $code && isset( $languages[ $code ] ) ) { return $code; }
        $site = Language::site_code();
        return isset( $languages[ $site ] ) ? $site : (string) array_key_first( $languages );
    }

    public static function registered( string $code ): bool {
        return isset( self::all()[ $code ] );
    }

    public static function enabled( string $code ): bool {
        $langs = self::all();
        return isset( $langs[ $code ] ) && ! empty( $langs[ $code ]['enabled'] );
    }

    /** Public, indexable language root, or no fallback; never invent routes. */
    public static function home_url( string $code ): string {
        $langs = self::all();
        if ( empty( $langs[ $code ]['enabled'] ) ) { return ''; }
        $id = (int) ( $langs[ $code ]['home_id'] ?? 0 );
        if ( $id > 0 ) {
            $post = get_post( $id );
            if ( $post && Translation_Groups::indexable_url( $post ) ) {
                return (string) get_permalink( $post );
            }
        }
        // Preserve legacy WordPress root for a site with no assigned static front page.
        if ( $code === self::default_code() && ! $id && ! (int) get_option( 'page_on_front', 0 ) ) {
            return home_url( '/' );
        }
        return '';
    }

    /**
     * Validate and store a language without touching any translations or SEO metadata.
     * The settings update triggers existing Sitemap cache invalidation.
     */
    public static function save( string $code, string $name, int $home_id, bool $enabled ): bool|\WP_Error {
        $locale = Language::normalize( $code );
        if ( '' === $locale || '' === trim( $name ) || strlen( $name ) > 160 ) {
            return new \WP_Error( 'invalid_locale', '请输入合法语言代码和名称。' );
        }
        if ( $home_id ) {
            $post = get_post( $home_id );
            if ( ! $post || 'page' !== $post->post_type ) {
                return new \WP_Error( 'invalid_homepage', '语言首页必须是本站现有的 WordPress 页面。' );
            }
        }
        $settings = Settings::all();
        $languages = self::all();
        $languages[ $locale ] = array(
            'name'=>sanitize_text_field( $name ), 'home_id'=>$home_id, 'enabled'=>$enabled,
        );
        $settings['international']['languages'] = $languages;
        if ( empty( $settings['international']['default_locale'] ) ) {
            $settings['international']['default_locale'] = Language::site_code();
        }
        return Settings::update( $settings );
    }

    public static function delete( string $code ): bool|\WP_Error {
        $languages = self::all();
        if ( ! isset( $languages[ $code ] ) ) { return new \WP_Error( 'unknown_locale', '语言未注册。' ); }
        if ( $code === self::default_code() ) { return new \WP_Error( 'default_locale', '请先修改默认语言。' ); }
        if ( Translation_Groups::count_language( $code ) ) {
            return new \WP_Error( 'assigned_locale', '此语言仍有关联页面，请先解除关联。' );
        }
        unset( $languages[ $code ] );
        $settings = Settings::all();
        $settings['international']['languages'] = $languages;
        return Settings::update( $settings );
    }

    public static function set_options( string $default, string $x_default, bool $active ): bool|\WP_Error {
        $languages = self::all();
        if ( ! isset( $languages[ $default ] ) || empty( $languages[ $default ]['enabled'] ) ) {
            return new \WP_Error( 'invalid_default', '默认语言必须已启用。' );
        }
        if ( '' !== $x_default && ( ! isset( $languages[ $x_default ] )
            || empty( $languages[ $x_default ]['enabled'] ) ) ) {
            return new \WP_Error( 'invalid_xdefault', 'x-default 语言必须已启用。' );
        }
        $settings = Settings::all();
        $settings['international']['default_locale'] = $default;
        $settings['international']['x_default_language'] = $x_default;
        $settings['international']['id_links_enabled'] = $active;
        return Settings::update( $settings );
    }

    /** One admin-only indexed query for counts; no per-visitor full-table scans. */
    public static function unlinked_counts(): array {
        global $wpdb;
        $table = \AIKEY\SeoGeo\Core\Database::table( 'translation_members' );
        // Read only in the admin; no visitor-side site scans.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT COALESCE(NULLIF(lang.meta_value, ''), %s) AS locale,
                COUNT(DISTINCT posts.ID) AS count
             FROM {$wpdb->posts} AS posts
             LEFT JOIN {$table} AS members ON members.post_id = posts.ID
             LEFT JOIN {$wpdb->postmeta} AS lang
                ON lang.post_id = posts.ID AND lang.meta_key = '_aikey_page_language'
             WHERE members.post_id IS NULL
                AND posts.post_type IN ('page','post','product_cases')
                AND posts.post_status IN ('publish','draft','pending','private','future')
             GROUP BY locale",
            self::default_code()
        ), ARRAY_A );
        $out = array();
        foreach ( is_array( $rows ) ? $rows : array() as $row ) {
            $out[ (string) $row['locale'] ] = (int) $row['count'];
        }
        return $out;
    }

    public static function counts(): array {
        global $wpdb;
        $table = \AIKEY\SeoGeo\Core\Database::table( 'translation_members' );
        $rows = $wpdb->get_results( "SELECT language, COUNT(*) AS count FROM {$table} GROUP BY language", ARRAY_A );
        $counts = array();
        foreach ( is_array( $rows ) ? $rows : array() as $row ) {
            $counts[ (string) $row['language'] ] = (int) $row['count'];
        }
        return $counts;
    }
}
