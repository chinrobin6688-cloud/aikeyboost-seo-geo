<?php
namespace AIKEY\SeoGeo\SEO\Meta;

use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\GEO\Language;

defined( 'ABSPATH' ) || exit;

final class Meta_Repository {
    public const TITLE          = '_aikey_seo_title';
    public const DESCRIPTION    = '_aikey_seo_description';
    public const CANONICAL      = '_aikey_seo_canonical';
    public const ROBOTS_INDEX   = '_aikey_seo_robots_index';
    public const ROBOTS_FOLLOW  = '_aikey_seo_robots_follow';
    public const FOCUS_KEYWORD  = '_aikey_focus_keyword';
    public const CORE_KEYWORDS   = '_aikey_core_keywords';
    public const RELATED_KEYWORDS= '_aikey_related_keywords';
    public const SEARCH_QUESTIONS= '_aikey_search_questions';
    public const SCHEMA_TYPE    = '_aikey_schema_type';
    public const GEO_ENTITY     = '_aikey_geo_entity';
    public const GEO_ENTITY_TYPE= '_aikey_geo_entity_type';
    public const GEO_FACTS      = '_aikey_geo_facts';
    public const GEO_FAQ        = '_aikey_geo_faq';
    public const GEO_SOURCES    = '_aikey_geo_sources';
    public const GEO_SCORE      = '_aikey_geo_score';
    public const GEO_BREAKDOWN  = '_aikey_geo_breakdown';
    public const SEO_SCORE      = '_aikey_seo_score';
    public const SEO_ISSUES     = '_aikey_seo_issues';
    public const AI_ANALYSIS    = '_aikey_ai_analysis';

    public static function get( int $post_id, string $key ): string {
        $value = get_post_meta( $post_id, $key, true );
        return is_string( $value ) ? $value : '';
    }
    public static function title( int $post_id ): string { return self::get( $post_id, self::TITLE ); }
    public static function description( int $post_id ): string { return self::get( $post_id, self::DESCRIPTION ); }
    public static function canonical( int $post_id ): string { return self::get( $post_id, self::CANONICAL ); }
    public static function resolved_canonical_url( \WP_Post $post ): string {
        $custom = trim( self::canonical( (int) $post->ID ) );
        return '' !== $custom ? $custom : (string) get_permalink( $post );
    }

    public static function front_page_id(): int {
        if ( 'page' !== (string) get_option( 'show_on_front', 'posts' ) ) { return 0; }
        return absint( get_option( 'page_on_front', 0 ) );
    }

    public static function resolve_seo_title( int $post_id = 0, bool $is_front = false, string $fallback = '' ): string {
        if ( $is_front ) {
            $front_id = self::front_page_id();
            if ( $front_id > 0 ) {
                $page_title = trim( self::title( $front_id ) );
                if ( '' !== $page_title ) { return $page_title; }
            }

            $legacy_home = trim( (string) Settings::seo_meta( 'home_title', '' ) );
            if ( '' !== $legacy_home ) { return $legacy_home; }

            if ( $front_id > 0 ) {
                $front_post = get_post( $front_id );
                if ( $front_post instanceof \WP_Post ) {
                    return self::render_title_template( $front_post );
                }
            }

            $fallback = trim( $fallback );
            return '' !== $fallback ? $fallback : trim( (string) get_bloginfo( 'name' ) );
        }

        if ( $post_id > 0 ) {
            $custom = trim( self::title( $post_id ) );
            if ( '' !== $custom ) { return $custom; }
            $post = get_post( $post_id );
            if ( $post instanceof \WP_Post ) { return self::render_title_template( $post ); }
        }

        return trim( $fallback );
    }

    public static function resolve_seo_description( int $post_id = 0, bool $is_front = false ): string {
        if ( $is_front ) {
            $front_id = self::front_page_id();
            if ( $front_id > 0 ) {
                $page_description = trim( self::description( $front_id ) );
                if ( '' !== $page_description ) { return $page_description; }
            }

            $legacy_home = trim( (string) Settings::seo_meta( 'home_description', '' ) );
            if ( '' !== $legacy_home ) { return $legacy_home; }

            if ( $front_id > 0 ) {
                $front_post = get_post( $front_id );
                if ( $front_post instanceof \WP_Post ) {
                    return self::effective_description( $front_post );
                }
            }

            if ( Settings::seo_meta( 'auto_description_fallback', true ) ) {
                return trim( (string) get_bloginfo( 'description' ) );
            }
            return '';
        }

        if ( $post_id > 0 ) {
            $post = get_post( $post_id );
            if ( $post instanceof \WP_Post ) { return self::effective_description( $post ); }
        }
        return '';
    }
    public static function focus_keyword( int $post_id ): string { return self::get( $post_id, self::FOCUS_KEYWORD ); }
    public static function core_keywords( int $post_id ): string {
        $core = trim( self::get( $post_id, self::CORE_KEYWORDS ) );
        return '' !== $core ? $core : self::focus_keyword( $post_id );
    }
    public static function sync_primary_keyword( int $post_id, string $primary ): void {
        $primary = trim( sanitize_text_field( $primary ) );
        if ( '' === $primary ) {
            delete_post_meta( $post_id, self::CORE_KEYWORDS );
            return;
        }
        $parts = preg_split( '/[\r\n,，;；、]+/u', self::get( $post_id, self::CORE_KEYWORDS ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
        $out = array( $primary => $primary );
        foreach ( $parts as $part ) {
            $part = trim( sanitize_text_field( (string) $part ) );
            if ( '' === $part || $part === $primary ) { continue; }
            $out[ $part ] = $part;
            if ( count( $out ) >= 3 ) { break; }
        }
        update_post_meta( $post_id, self::CORE_KEYWORDS, implode( '，', array_values( $out ) ) );
    }
    public static function schema_type( int $post_id ): string { return self::get( $post_id, self::SCHEMA_TYPE ); }

    public static function robots_index( int $post_id ): string {
        $value = self::get( $post_id, self::ROBOTS_INDEX );
        return in_array( $value, array( 'default', 'index', 'noindex' ), true ) ? $value : 'default';
    }
    public static function robots_follow( int $post_id ): string {
        $value = self::get( $post_id, self::ROBOTS_FOLLOW );
        return in_array( $value, array( 'default', 'follow', 'nofollow' ), true ) ? $value : 'default';
    }

    public static function preview_title( \WP_Post $post ): string {
        $custom = self::title( (int) $post->ID );
        return '' !== $custom ? $custom : self::render_title_template( $post );
    }

    public static function effective_description( \WP_Post $post ): string {
        $custom = self::description( (int) $post->ID );
        if ( '' !== $custom ) { return $custom; }
        if ( ! Settings::seo_meta( 'auto_description_fallback', true ) ) { return ''; }
        if ( ! preg_match( '/^(?:zh|ja|ko)(?:-|$)/i', Language::code( $post ) ) ) {
            return Language::automatic_description( $post );
        }
        $excerpt = has_excerpt( $post ) ? $post->post_excerpt : $post->post_content;
        $text = wp_strip_all_tags( strip_shortcodes( (string) $excerpt ), true );
        $text = preg_replace( '/\s+/u', ' ', $text );
        return self::truncate( is_string( $text ) ? trim( $text ) : '', 160 );
    }

    public static function render_title_template( \WP_Post $post ): string {
        $template = (string) Settings::seo_meta( 'title_template', '%title% %sep% %sitename%' );
        $separator = trim( (string) Settings::seo_meta( 'separator', '-' ) );
        $title = strtr( $template, array(
            '%title%' => get_the_title( $post ),
            '%sep%' => '' !== $separator ? $separator : '-',
            '%sitename%' => get_bloginfo( 'name' ),
            '%posttype%' => get_post_type_object( $post->post_type )?->labels?->singular_name ?? $post->post_type,
        ) );
        $title = preg_replace( '/\s+/u', ' ', $title );
        return is_string( $title ) ? trim( $title ) : get_the_title( $post );
    }

    public static function truncate( string $text, int $length ): string {
        if ( '' === $text ) { return ''; }
        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            return mb_strlen( $text, 'UTF-8' ) <= $length ? $text : rtrim( mb_substr( $text, 0, $length, 'UTF-8' ) ) . '…';
        }
        // Do not cut into the middle of a UTF-8 character when mbstring is absent.
        $chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
        if ( is_array( $chars ) ) {
            return count( $chars ) <= $length ? $text : rtrim( implode( '', array_slice( $chars, 0, $length ) ) ) . '…';
        }
        return strlen( $text ) <= $length ? $text : rtrim( substr( $text, 0, $length ) ) . '…';
    }
}
