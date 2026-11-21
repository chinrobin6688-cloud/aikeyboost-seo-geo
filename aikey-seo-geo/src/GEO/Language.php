<?php
namespace AIKEY\SeoGeo\GEO;

use AIKEY\SeoGeo\Core\Settings;

defined( 'ABSPATH' ) || exit;

/** Shared public-language resolver used by SEO, Schema and multilingual links. */
final class Language {
    public static function normalize( string $raw, bool $allow_default = false ): string {
        $raw = trim( str_replace( '_', '-', $raw ) );
        if ( $allow_default && 'x-default' === strtolower( $raw ) ) { return 'x-default'; }
        if ( strlen( $raw ) > 55 || ! preg_match( '/^[a-z]{2,8}(?:-[a-z0-9]{1,8}){0,8}$/i', $raw ) ) { return ''; }
        $parts = explode( '-', strtolower( $raw ) );
        foreach ( $parts as $i => $value ) {
            if ( 0 === $i ) { continue; }
            if ( 4 === strlen( $value ) && ctype_alpha( $value ) ) { $parts[$i] = ucfirst( $value ); }
            elseif ( ( 2 === strlen( $value ) && ctype_alpha( $value ) ) || ( 3 === strlen( $value ) && ctype_digit( $value ) ) ) {
                $parts[$i] = strtoupper( $value );
            }
        }
        return implode( '-', $parts );
    }

    public static function site_code(): string {
        $config = Settings::section( 'international' );
        $code = self::normalize( (string) ( $config['site_locale'] ?? '' ) );
        if ( '' !== $code ) { return $code; }
        $code = self::normalize( (string) get_bloginfo( 'language' ) );
        return '' !== $code ? $code : 'en';
    }

    public static function code( ?\WP_Post $post = null ): string {
        if ( $post ) {
            // ID bindings take precedence only after the new output gate is enabled.
            // This avoids an unnecessary translation query on legacy frontends.
            if ( ! empty( Settings::section( 'international' )['id_links_enabled'] ) ) {
                $bound = \AIKEY\SeoGeo\SEO\International\Translation_Groups::language_for( (int) $post->ID );
                if ( '' !== $bound ) { return $bound; }
            }
            $code = self::normalize( (string) get_post_meta( $post->ID, '_aikey_page_language', true ) );
            if ( '' !== $code ) { return $code; }
        }
        $config = Settings::section( 'international' );
        $code = self::normalize( (string) ( $config['site_locale'] ?? '' ) );
        if ( '' !== $code ) { return $code; }

        // Preserve the legacy /en/ route behavior for existing single-site bilingual setups.
        $source = $post ? (string) get_permalink( $post ) : (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        $path = (string) wp_parse_url( $source, PHP_URL_PATH );
        $root = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
        if ( '' !== $root && str_starts_with( $path, $root . '/' ) ) {
            $path = substr( $path, strlen( $root ) );
        }
        $segment = explode( '/', trim( $path, '/' ), 2 )[0] ?? '';
        $candidate = self::normalize( $segment );
        if ( '' !== $candidate
            && class_exists( \AIKEY\SeoGeo\SEO\International\Language_Registry::class )
            && \AIKEY\SeoGeo\SEO\International\Language_Registry::enabled( $candidate ) ) {
            return $candidate;
        }
        return preg_match( '#^/en(?:/|$)#i', $path ) ? 'en' : self::site_code();
    }

    public static function is_english( ?\WP_Post $post = null ): bool {
        return (bool) preg_match( '/^en(?:-|$)/i', self::code( $post ) );
    }

    /** Avoid obviously cross-language automatic descriptions; never fabricate text. */
    public static function automatic_description( \WP_Post $post ): string {
        $source = has_excerpt( $post ) ? $post->post_excerpt : $post->post_content;
        $text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( (string) $source ) ) ) );
        if ( '' === $text ) { return ''; }
        $code = self::code( $post );
        if ( ! preg_match( '/^(?:zh|ja|ko)(?:-|$)/i', $code ) && preg_match( '/\p{Han}/u', $text ) ) { return ''; }
        if ( preg_match( '/^ar(?:-|$)/i', $code ) && ! preg_match( '/\p{Arabic}/u', $text ) ) { return ''; }
        return \AIKEY\SeoGeo\SEO\Meta\Meta_Repository::truncate( $text, 160 );
    }

    /** Historical helper retained for compatibility with existing callers. */
    public static function english_description( \WP_Post $post ): string {
        return self::automatic_description( $post );
    }
}
