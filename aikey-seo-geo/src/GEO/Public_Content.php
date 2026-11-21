<?php
namespace AIKEY\SeoGeo\GEO;

use AIKEY\SeoGeo\SEO\Meta\Meta_Repository;
use AIKEY\SeoGeo\Support\Utils;

defined( 'ABSPATH' ) || exit;

/** Explicit, visible publication of existing GEO metadata. */
final class Public_Content {
    public static function register(): void {
        add_shortcode( 'aikey_geo_facts', array( self::class, 'render_facts' ) );
        add_shortcode( 'aikey_geo_faq', array( self::class, 'render_faq' ) );
        add_shortcode( 'aikey_geo_sources', array( self::class, 'render_sources' ) );
    }

    private static function public_post( \WP_Post $p ): bool {
        return 'publish' === $p->post_status && '' === (string) $p->post_password
            && in_array( $p->post_type, Utils::public_post_types(), true );
    }

    private static function queried_post(): ?\WP_Post {
        if ( is_admin() || ! is_singular() ) { return null; }
        $p = get_queried_object();
        return $p instanceof \WP_Post && self::public_post( $p ) ? $p : null;
    }

    public static function facts( \WP_Post $p ): array {
        $out = array();
        foreach ( Utils::clean_lines( Meta_Repository::get( $p->ID, Meta_Repository::GEO_FACTS ), 60 ) as $line ) {
            $text = Meta_Repository::truncate( trim( wp_strip_all_tags( $line ) ), 300 );
            if ( '' !== $text ) { $out[$text] = $text; }
            if ( count( $out ) >= 30 ) { break; }
        }
        return array_values( $out );
    }

    public static function faq( \WP_Post $p ): array {
        $out = array();
        foreach ( Scorer::faq_pairs( Meta_Repository::get( $p->ID, Meta_Repository::GEO_FAQ ) ) as $pair ) {
            $q = Meta_Repository::truncate( sanitize_text_field( $pair['q'] ), 250 );
            $a = Meta_Repository::truncate( sanitize_text_field( $pair['a'] ), 1000 );
            if ( $q && $a ) { $out[$q . '||' . $a] = array( 'q'=>$q, 'a'=>$a ); }
            if ( count( $out ) >= 30 ) { break; }
        }
        return array_values( $out );
    }

    public static function sources( \WP_Post $p ): array {
        $out = array();
        foreach ( Utils::clean_lines( Meta_Repository::get( $p->ID, Meta_Repository::GEO_SOURCES ), 30 ) as $line ) {
            $url = esc_url_raw( $line, array( 'http', 'https' ) );
            if ( $url && wp_parse_url( $url, PHP_URL_HOST ) ) { $out[$url] = $url; }
            if ( count( $out ) >= 20 ) { break; }
        }
        return array_values( $out );
    }

    private static function contains( string $text, string $needle ): bool {
        $needle = trim( (string) preg_replace( '/\s+/u', ' ', $needle ) );
        return '' !== $needle && ( function_exists( 'mb_stripos' )
            ? false !== mb_stripos( $text, $needle, 0, 'UTF-8' )
            : false !== stripos( $text, $needle ) );
    }

    private static function visible_text( \WP_Post $p ): string {
        return trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $p->post_content ) ) ) );
    }

    public static function visible_facts( \WP_Post $p ): array {
        if ( ! self::public_post( $p ) ) { return array(); }
        $facts = self::facts( $p );
        if ( has_shortcode( $p->post_content, 'aikey_geo_facts' ) ) { return $facts; }
        $text = self::visible_text( $p );
        return array_values( array_filter( $facts, static fn( string $f ): bool => self::contains( $text, $f ) ) );
    }

    public static function visible_faq( \WP_Post $p ): array {
        if ( ! self::public_post( $p ) ) { return array(); }
        $pairs = self::faq( $p );
        if ( has_shortcode( $p->post_content, 'aikey_geo_faq' ) ) { return $pairs; }
        $text = self::visible_text( $p );
        return array_values( array_filter( $pairs, static fn( array $f ): bool =>
            self::contains( $text, $f['q'] ) && self::contains( $text, $f['a'] ) ) );
    }

    public static function visible_sources( \WP_Post $p ): array {
        if ( ! self::public_post( $p ) ) { return array(); }
        $urls = self::sources( $p );
        if ( has_shortcode( $p->post_content, 'aikey_geo_sources' ) ) { return $urls; }
        return array_values( array_filter( $urls, static fn( string $url ): bool =>
            (bool) preg_match( '/href\s*=\s*["\x27]' . preg_quote( $url, '/' ) . '["\x27]/i', $p->post_content ) ) );
    }

    public static function render_facts(): string {
        $p = self::queried_post(); if ( ! $p ) { return ''; }
        $facts = self::facts( $p ); if ( ! $facts ) { return ''; }
        $title = Language::is_english( $p ) ? 'Key Facts' : '核心事实';
        $html = '<section class="aikey-geo-facts" aria-label="' . esc_attr( $title ) . '"><h2>' . esc_html( $title ) . '</h2><ul>';
        foreach ( $facts as $fact ) { $html .= '<li>' . esc_html( $fact ) . '</li>'; }
        return $html . '</ul></section>';
    }

    public static function render_faq(): string {
        $p = self::queried_post(); if ( ! $p ) { return ''; }
        $pairs = self::faq( $p ); if ( ! $pairs ) { return ''; }
        $title = Language::is_english( $p ) ? 'Frequently Asked Questions' : '常见问题';
        $html = '<section class="aikey-geo-faq" aria-label="' . esc_attr( $title ) . '"><h2>' . esc_html( $title ) . '</h2>';
        foreach ( $pairs as $pair ) {
            $html .= '<div class="aikey-geo-faq-item"><h3>' . esc_html( $pair['q'] ) . '</h3><p>' . esc_html( $pair['a'] ) . '</p></div>';
        }
        return $html . '</section>';
    }

    public static function render_sources(): string {
        $p = self::queried_post(); if ( ! $p ) { return ''; }
        $sources = self::sources( $p ); if ( ! $sources ) { return ''; }
        $title = Language::is_english( $p ) ? 'Sources' : '参考来源';
        $html = '<section class="aikey-geo-sources" aria-label="' . esc_attr( $title ) . '"><h2>' . esc_html( $title ) . '</h2><ul>';
        foreach ( $sources as $url ) {
            $html .= '<li><a href="' . esc_url( $url ) . '" rel="noopener noreferrer">' . esc_html( $url ) . '</a></li>';
        }
        return $html . '</ul></section>';
    }
}
