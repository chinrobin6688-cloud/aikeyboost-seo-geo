<?php
namespace AIKEY\SeoGeo\SEO\Sitemap;

use AIKEY\SeoGeo\SEO\Meta\Meta_Repository;
use AIKEY\SeoGeo\SEO\Meta\Term_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Small, versioned transient cache for generated Sitemap payloads.
 *
 * Cache invalidation increments one namespace version instead of enumerating old
 * transients. Expiring transients are never autoloaded by WordPress. Oversized
 * XML is deliberately not written to the options table.
 */
final class Sitemap_Cache {
    private const VERSION_OPTION = 'aikey_seo_geo_sitemap_cache_version';
    private const STATS_OPTION   = 'aikey_seo_geo_sitemap_runtime_stats';
    private const TTL            = 12 * HOUR_IN_SECONDS;
    private const MAX_BODY_BYTES = 4000000;

    private static bool $invalidated_this_request = false;

    public static function register_hooks(): void {
        foreach ( array( 'save_post', 'transition_post_status', 'before_delete_post', 'deleted_post', 'trashed_post', 'untrashed_post' ) as $hook ) {
            add_action( $hook, array( self::class, 'invalidate_event' ), 20, 0 );
        }
        foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
            add_action( $hook, array( self::class, 'invalidate_event' ), 20, 0 );
        }
        add_action( 'added_post_meta', array( self::class, 'post_meta_changed' ), 20, 4 );
        add_action( 'updated_post_meta', array( self::class, 'post_meta_changed' ), 20, 4 );
        add_action( 'deleted_post_meta', array( self::class, 'post_meta_changed' ), 20, 4 );
        add_action( 'added_term_meta', array( self::class, 'term_meta_changed' ), 20, 4 );
        add_action( 'updated_term_meta', array( self::class, 'term_meta_changed' ), 20, 4 );
        add_action( 'deleted_term_meta', array( self::class, 'term_meta_changed' ), 20, 4 );
        add_action( 'updated_option', array( self::class, 'option_changed' ), 20, 3 );
        add_action( 'aikey_seo_geo_redirects_changed', array( self::class, 'invalidate_event' ), 20, 0 );
        add_action( 'switch_theme', array( self::class, 'invalidate_event' ), 20, 0 );
    }

    public static function invalidate_event(): void { self::invalidate(); }

    public static function post_meta_changed( mixed $meta_id, mixed $object_id, mixed $meta_key, mixed $meta_value ): void {
        if ( in_array( (string) $meta_key, array( Meta_Repository::ROBOTS_INDEX, Meta_Repository::CANONICAL ), true ) ) {
            self::invalidate();
        }
    }

    public static function term_meta_changed( mixed $meta_id, mixed $object_id, mixed $meta_key, mixed $meta_value ): void {
        if ( Term_Meta::ROBOTS_INDEX === (string) $meta_key ) { self::invalidate(); }
    }

    public static function option_changed( mixed $option, mixed $old_value, mixed $value ): void {
        if ( in_array(
            (string) $option,
            array(
                'aikey_seo_geo_settings',
                'permalink_structure',
                'show_on_front',
                'page_on_front',
                'page_for_posts',
                'blog_public',
                'home',
                'siteurl',
            ),
            true
        ) ) {
            self::invalidate();
        }
    }

    public static function invalidate(): void {
        if ( self::$invalidated_this_request ) { return; }
        self::$invalidated_this_request = true;
        self::bump_version();
    }

    /** Force a fresh namespace for an explicit administrator refresh. */
    public static function refresh(): void {
        self::$invalidated_this_request = true;
        self::bump_version();
    }

    private static function bump_version(): void {
        $next = self::version() + 1;
        update_option( self::VERSION_OPTION, (string) $next, false );
        delete_option( self::STATS_OPTION );
    }

    public static function version(): int {
        return max( 1, (int) get_option( self::VERSION_OPTION, 1 ) );
    }

    /** @return array<string,mixed>|null */
    public static function get( string $key ): ?array {
        $payload = get_transient( self::key( $key ) );
        if ( ! is_array( $payload ) || ! isset( $payload['body'] ) || ! is_string( $payload['body'] ) ) { return null; }
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    public static function set( string $key, array $payload ): bool {
        $body = isset( $payload['body'] ) && is_string( $payload['body'] ) ? $payload['body'] : '';
        if ( '' === $body || strlen( $body ) > self::MAX_BODY_BYTES ) { return false; }
        $stored = set_transient( self::key( $key ), $payload, self::TTL );
        if ( $stored ) {
            set_transient( self::marker_key( $key ), '1', self::TTL );
            if ( 'main' === $key ) {
                $stats = self::stats();
                if ( $stats ) {
                    $stats['cache_updated_at'] = time();
                    $stats['cache_version'] = self::version();
                    update_option( self::STATS_OPTION, $stats, false );
                }
            }
        }
        return $stored;
    }

    public static function is_cached( string $key ): bool { return '1' === (string) get_transient( self::marker_key( $key ) ); }

    /** @param array<string,mixed> $stats */
    public static function set_stats( array $stats ): void {
        $stats['cache_version'] = self::version();
        update_option( self::STATS_OPTION, $stats, false );
    }

    /** @return array<string,mixed> */
    public static function stats(): array {
        $stats = get_option( self::STATS_OPTION, array() );
        return is_array( $stats ) ? $stats : array();
    }

    /**
     * Store only the rare cross-child duplicate ownership map for index mode.
     * Keys are SHA-256 URL fingerprints, values are the first child cache key.
     * The map is versioned with the Sitemap namespace and expires naturally.
     *
     * @param array<string,string> $owners
     */
    public static function set_duplicate_owners( array $owners ): void {
        $clean = array();
        foreach ( $owners as $fingerprint => $owner ) {
            $fingerprint = strtolower( trim( (string) $fingerprint ) );
            $owner = sanitize_key( (string) $owner );
            if ( 64 !== strlen( $fingerprint ) || ! ctype_xdigit( $fingerprint ) || '' === $owner ) { continue; }
            $clean[ $fingerprint ] = $owner;
            if ( count( $clean ) >= 5000 ) { break; }
        }
        if ( $clean ) {
            set_transient( self::duplicate_key(), $clean, self::TTL );
        } else {
            delete_transient( self::duplicate_key() );
        }
    }

    /** @return array<string,string> */
    public static function duplicate_owners(): array {
        $owners = get_transient( self::duplicate_key() );
        return is_array( $owners ) ? $owners : array();
    }

    private static function key( string $key ): string {
        return 'aikey_sm_' . md5( self::version() . '|' . sanitize_key( $key ) );
    }

    private static function marker_key( string $key ): string {
        return 'aikey_smm_' . md5( self::version() . '|' . sanitize_key( $key ) );
    }

    private static function duplicate_key(): string {
        return 'aikey_smd_' . md5( (string) self::version() );
    }
}
