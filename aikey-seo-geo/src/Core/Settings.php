<?php
namespace AIKEY\SeoGeo\Core;

use AIKEY\SeoGeo\Integrations\Engine_Registry;

defined( 'ABSPATH' ) || exit;

final class Settings {
    private const OPTION = 'aikey_seo_geo_settings';
    private const MODULE_DEFAULTS = array(
        'seo_meta'        => true,
        'sitemap'         => true,
        'schema'          => true,
        'redirections'    => true,
        'monitor_404'     => true,
        'seo_audit'       => true,
        'geo'             => true,
        'llms'            => true,
        'ai'              => true,
        'referrals'       => true,
        'baidu'           => true,
        'china_engines'   => true,
        'indexnow'        => true,
        'crawler_monitor' => true,
        'automation'      => true,
        'internal_links'  => true,
        'enterprise_map'  => true,
        'baidu_analytics' => true,
    );
    private static ?array $cache = null;

    public static function defaults(): array {
        return array(
            // Public distribution: avoid overriding another SEO plugin or adding
            // a visitor-facing attribution without site-owner consent.
            // Previously saved choices still take precedence on upgrade.
            'keep_data_on_uninstall' => true,
            'force_takeover'         => false,
            'show_footer_credit'     => false, // Explicit opt-in only; never force a public attribution link.
            'modules'                => self::MODULE_DEFAULTS,
            'seo_meta'               => array(
                'title_template'            => '%title% %sep% %sitename%',
                'separator'                 => '-',
                'auto_description_fallback' => true,
                'home_title'                => '',
                'home_description'          => '',
                'open_graph'                => true,
            ),
            'schema'                 => array(
                'organization_type' => 'Organization',
                'organization_name' => '',
                'logo_url'          => '',
                'telephone'         => '',
                'email'             => '',
                'address'           => '',
                'service_area'      => '中国大陆',
                'localized_organizations' => array(),
                'unified_social_credit_code' => '',
                'icp_number'        => '',
                'same_as'           => '',
            ),
            'map'                    => array(
                'address'             => '', // Optional map-specific address; blank uses Schema address.
                'forms'               => array(), // Named, multilingual per-page form configurations.
                'height'              => 340,
                'show_address'        => true,
                'show_navigation'     => true,
                'navigation_provider' => 'both',
            ),
            'sitemap'                => array(
                'max_urls'       => 5000,
                'baidu_direct_limit' => 50000,
                'disable_core_sitemap' => true,
                'exclude_post_ids' => array(),
                'exclude_urls'     => array(),
                // Empty maps mean use safe defaults for currently registered types:
                // public Page/Post/CPT enabled; category enabled; tags/custom taxonomies off.
                'post_types'     => array(),
                'taxonomies'     => array(),
            ),
            'international'         => array(
                'enabled' => false,
                'site_locale' => '',
                'languages' => array(), // BCP47 keyed registry; empty uses site language until configured.
                'default_locale' => '',
                'x_default_language' => '',
                'id_links_enabled' => false, // Explicit migration gate, off until WPCode output is reconciled.
            ),
            'geo'                    => array(
                // Legacy compatibility. v1.1+ uses china_engines.crawler_policies.
                'ai_crawlers' => 'allow',
            ),
            'china_engines'          => array(
                'enabled' => Engine_Registry::default_enabled(),
                'crawler_policies' => Engine_Registry::default_crawler_policies(),
                'bot_policies'     => array(), // Optional global AI search/user/training token overrides.
                'verification' => Engine_Registry::default_verifications(),
                'baidu_location_province' => '',
                'baidu_location_city' => '',
                'baidu_location_coord' => '',
                'baidu_applicable_device' => true,
            ),
            'site_verification'       => array(
                'entries' => array(),
                'custom'  => array(),
            ),
            'indexnow'               => array(
                'auto_submit' => false, // Fresh installs must explicitly enable external URL submission.
                'key' => '',
            ),
            'submission'             => array(
                'daily_incremental' => false, // Fresh installs must explicitly enable scheduled external submission.
                'daily_limit'       => 19,
            ),
            'automation'              => array(
                'mode' => 'recommended',
                'optimize_on_save' => true,
                'auto_score' => true,
                'auto_schema' => true,
                'fill_empty_entity' => false,
                'daily_maintenance' => true,
                'daily_scan_limit' => 200,
            ),
            'llms'                   => array(
                'max_links' => 50,
            ),
            'ai'                     => array(
                'provider' => 'deepseek',
                'base_url' => 'https://api.deepseek.com',
                'model'    => 'deepseek-v4-flash',
                'api_key'  => '',
            ),
            'baidu_analytics'       => array(
                'site_id'        => '',
                'exclude_admins' => true,
            ),
            'baidu'                  => array(
                'site'        => '',
                'token'       => '',
                'auto_submit' => false,
                'auto_submit_updates' => false,
            ),
        );
    }

    public static function all(): array {
        if ( null === self::$cache ) {
            $settings = get_option( self::OPTION, array() );
            $settings = is_array( $settings ) ? $settings : array();
            self::$cache = self::merge_recursive( self::defaults(), $settings );
        }
        return self::$cache;
    }

    public static function get( string $key, mixed $default = null ): mixed {
        $settings = self::all();
        return $settings[ $key ] ?? $default;
    }

    public static function section( string $section ): array {
        $value = self::get( $section, array() );
        return is_array( $value ) ? $value : array();
    }

    public static function section_get( string $section, string $key, mixed $default = null ): mixed {
        $values = self::section( $section );
        return $values[ $key ] ?? $default;
    }

    public static function seo_meta( string $key, mixed $default = null ): mixed {
        return self::section_get( 'seo_meta', $key, $default );
    }

    /**
     * Core plugin modules are always enabled from v2.0.2 onward. The legacy
     * modules option is kept for upgrade/API compatibility only. Baidu Analytics
     * remains an explicit third-party tracking opt-in/out inside its own panel.
     */
    public static function module_enabled( string $module ): bool {
        if ( ! array_key_exists( $module, self::MODULE_DEFAULTS ) ) { return false; }
        if ( 'baidu_analytics' === $module ) {
            $modules = self::get( 'modules', array() );
            return is_array( $modules ) && ! empty( $modules['baidu_analytics'] );
        }
        return true;
    }

    /** @return array<string,bool> */
    public static function module_states(): array {
        $states = self::MODULE_DEFAULTS;
        $states['baidu_analytics'] = self::module_enabled( 'baidu_analytics' );
        return $states;
    }

    public static function update( array $settings ): bool {
        $merged = self::merge_recursive( self::defaults(), $settings );
        $updated = update_option( self::OPTION, $merged, false );
        if ( $updated ) {
            self::$cache = $merged;
            return true;
        }

        // WordPress returns false both when the value did not change and when a
        // database write failed. Read the persisted value back so callers can
        // distinguish a harmless no-op from a real storage failure.
        $stored = get_option( self::OPTION, null );
        if ( is_array( $stored ) && $stored === $merged ) {
            self::$cache = $merged;
            return true;
        }

        // Never expose an unpersisted value from the per-request cache.
        self::$cache = null;
        return false;
    }

    public static function api_key(): string {
        if ( defined( 'AIKEY_DEEPSEEK_API_KEY' ) && is_string( AIKEY_DEEPSEEK_API_KEY ) && '' !== AIKEY_DEEPSEEK_API_KEY ) {
            return AIKEY_DEEPSEEK_API_KEY;
        }
        return (string) self::section_get( 'ai', 'api_key', '' );
    }

    private static function merge_recursive( array $defaults, array $settings ): array {
        foreach ( $defaults as $key => $value ) {
            if ( ! array_key_exists( $key, $settings ) ) {
                $settings[ $key ] = $value;
                continue;
            }
            if ( is_array( $value ) && is_array( $settings[ $key ] ) ) {
                $settings[ $key ] = self::merge_recursive( $value, $settings[ $key ] );
            }
        }
        return $settings;
    }
}
