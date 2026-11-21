<?php
namespace AIKEY\SeoGeo\Core;

defined( 'ABSPATH' ) || exit;

final class Conflict_Detector {
    /** @return string[] */
    public static function active_seo_plugins(): array {
        $plugins = array();
        if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) { $plugins[] = 'Rank Math'; }
        if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) { $plugins[] = 'Yoast SEO'; }
        if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) { $plugins[] = 'All in One SEO'; }
        if ( defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_init' ) ) { $plugins[] = 'SEOPress'; }
        if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) || function_exists( 'the_seo_framework' ) ) { $plugins[] = 'The SEO Framework'; }
        if ( defined( 'SLIM_SEO_VER' ) || defined( 'SLIM_SEO_VERSION' ) ) { $plugins[] = 'Slim SEO'; }
        return array_values( array_unique( $plugins ) );
    }

    /**
     * Sitemap-only conflicts which can exist without a full SEO suite.
     * Detection is deliberately conservative: only stable public class/constant
     * identifiers are used so an unrelated plugin is not disabled by guesswork.
     *
     * @return string[]
     */
    public static function active_sitemap_plugins(): array {
        $plugins = self::active_seo_plugins();
        if ( class_exists( 'GoogleSitemapGenerator' ) || defined( 'GOOGLE_SITEMAP_GENERATOR_VERSION' ) ) {
            $plugins[] = 'XML Sitemap Generator for Google';
        }
        if ( defined( 'SIMPLE_XML_SITEMAP_VERSION' ) ) { $plugins[] = 'Simple XML Sitemap'; }
        return array_values( array_unique( $plugins ) );
    }

    public static function has_conflict(): bool {
        return ! empty( self::active_seo_plugins() );
    }

    public static function has_sitemap_conflict(): bool {
        return ! empty( self::active_sitemap_plugins() );
    }

    public static function frontend_allowed(): bool {
        return (bool) Settings::get( 'force_takeover', false ) || ! self::has_conflict();
    }

    public static function sitemap_allowed(): bool {
        return (bool) Settings::get( 'force_takeover', false ) || ! self::has_sitemap_conflict();
    }

    public static function summary(): string {
        return implode( '、', self::active_seo_plugins() );
    }

    public static function sitemap_summary(): string {
        return implode( '、', self::active_sitemap_plugins() );
    }
}
