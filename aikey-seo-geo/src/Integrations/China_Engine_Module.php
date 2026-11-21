<?php
namespace AIKEY\SeoGeo\Integrations;

use AIKEY\SeoGeo\Core\Conflict_Detector;
use AIKEY\SeoGeo\Core\Module_Interface;
use AIKEY\SeoGeo\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class China_Engine_Module implements Module_Interface {
    public function id(): string { return 'china_engines'; }

    public function register(): void {
        add_action( 'wp_head', array( $this, 'output_baidu_meta' ), 3 );
        add_filter( 'robots_txt', array( $this, 'robots_rules' ), 35, 2 );
    }

    public function output_baidu_meta(): void {
        if ( is_admin() || ! Conflict_Detector::frontend_allowed() ) { return; }
        $settings = Settings::section( 'china_engines' );

        if ( Engine_Registry::is_enabled( 'baidu' ) && ! empty( $settings['baidu_applicable_device'] ) ) {
            echo '<meta name="applicable-device" content="pc,mobile" />' . "\n";
        }

        // Baidu location meta: province and city are both required by the public protocol.
        $province = sanitize_text_field( (string) ( $settings['baidu_location_province'] ?? '' ) );
        $city = sanitize_text_field( (string) ( $settings['baidu_location_city'] ?? '' ) );
        $coord = sanitize_text_field( (string) ( $settings['baidu_location_coord'] ?? '' ) );
        if ( Engine_Registry::is_enabled( 'baidu' ) && '' !== $province && '' !== $city ) {
            $parts = array();
            if ( '' !== $province ) { $parts[] = 'province=' . $province; }
            if ( '' !== $city ) { $parts[] = 'city=' . $city; }
            if ( '' !== $coord && preg_match( '/^-?\d{1,3}(?:\.\d+)?,\s*-?\d{1,3}(?:\.\d+)?$/', $coord ) ) { $parts[] = 'coord=' . $coord; }
            printf( '<meta name="location" content="%s" />' . "\n", esc_attr( implode( ';', $parts ) ) );
        }
    }

    public static function blocked_bots(): array {
        $settings = Settings::section( 'china_engines' );
        $policies = isset( $settings['crawler_policies'] ) && is_array( $settings['crawler_policies'] ) ? $settings['crawler_policies'] : array();
        $overrides = isset( $settings['bot_policies'] ) && is_array( $settings['bot_policies'] ) ? $settings['bot_policies'] : array();
        $blocks = array();
        foreach ( Engine_Registry::platforms() as $id => $platform ) {
            if ( ! Engine_Registry::is_enabled( $id ) || empty( $platform['bots'] ) ) { continue; }
            $policy = sanitize_key( (string) ( $policies[ $id ] ?? 'allow' ) );
            foreach ( (array) $platform['bots'] as $bot ) {
                $override = Global_Crawler_Policy::choice( $overrides, (string) $id, (string) $bot );
                if ( 'block' === $override || ( 'inherit' === $override && 'block' === $policy ) ) {
                    $blocks[ (string) $bot ] = true;
                }
            }
        }
        return array_keys( $blocks );
    }

    public function robots_rules( string $output, bool $public ): string {
        if ( ! $public || ! Conflict_Detector::frontend_allowed() ) { return $output; }
        // Once a physical robots.txt exists, the web server normally serves it
        // before WordPress. The physical file is the single authoritative source.
        try {
            if ( \AIKEY\SeoGeo\SEO\Sitemap\Robots_File_Manager::exists() ) { return $output; }
        } catch ( \Throwable $error ) {
            // Dynamic fallback remains available if the file manager cannot load.
        }
        $blocks = self::blocked_bots();
        if ( ! $blocks ) { return $output; }
        $block = "\n# AI企帮SEO&GEO - selected crawler blocks\n";
        foreach ( $blocks as $bot ) {
            $block .= 'User-agent: ' . $bot . "\nDisallow: /\n";
        }
        return rtrim( $output ) . "\n" . $block;
    }
}
