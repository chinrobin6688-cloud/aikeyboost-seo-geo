<?php
namespace AIKEY\SeoGeo\Integrations;

use AIKEY\SeoGeo\SEO\Meta\Meta_Repository;

defined( 'ABSPATH' ) || exit;

final class RankMath_Importer {
    private const KEYS = array( 'rank_math_title','rank_math_description','rank_math_canonical_url','rank_math_robots','rank_math_focus_keyword' );

    public static function available(): bool {
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( self::KEYS ), '%s' ) );
        $sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})", ...self::KEYS ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( $sql ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function preview(): array {
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( self::KEYS ), '%s' ) );
        $sql = $wpdb->prepare( "SELECT meta_key, COUNT(DISTINCT post_id) AS total FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) GROUP BY meta_key", ...self::KEYS ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $out = array();
        foreach ( $rows ?: array() as $row ) { $out[ $row->meta_key ] = (int) $row->total; }
        return $out;
    }

    public static function import( bool $overwrite = false ): array {
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( self::KEYS ), '%s' ) );
        $sql = $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})", ...self::KEYS ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $post_ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $stats = array( 'posts'=>0,'fields'=>0,'skipped'=>0 );

        foreach ( $post_ids as $post_id ) {
            $post_id = (int) $post_id;
            $post = get_post( $post_id );
            if ( ! $post instanceof \WP_Post ) { continue; }
            $stats['posts']++;

            $values = array(
                Meta_Repository::TITLE => self::resolve( (string) get_post_meta( $post_id, 'rank_math_title', true ), $post ),
                Meta_Repository::DESCRIPTION => self::resolve( (string) get_post_meta( $post_id, 'rank_math_description', true ), $post ),
                Meta_Repository::CANONICAL => self::resolve( (string) get_post_meta( $post_id, 'rank_math_canonical_url', true ), $post ),
                Meta_Repository::FOCUS_KEYWORD => (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
            );
            foreach ( $values as $new => $value ) {
                $value = trim( $value );
                if ( '' === $value ) { continue; }
                if ( Meta_Repository::CANONICAL === $new ) { $value = esc_url_raw( $value, array( 'http','https' ) ); }
                elseif ( Meta_Repository::DESCRIPTION === $new ) { $value = sanitize_textarea_field( $value ); }
                else { $value = sanitize_text_field( $value ); }
                if ( '' === $value ) { continue; }
                if ( ! $overwrite && '' !== (string) get_post_meta( $post_id, $new, true ) ) { $stats['skipped']++; continue; }
                update_post_meta( $post_id, $new, $value );
                $stats['fields']++;
            }
            if ( '' !== Meta_Repository::focus_keyword( $post_id ) && ( $overwrite || '' === Meta_Repository::get( $post_id, Meta_Repository::CORE_KEYWORDS ) ) ) {
                Meta_Repository::sync_primary_keyword( $post_id, Meta_Repository::focus_keyword( $post_id ) );
            }

            $robots = get_post_meta( $post_id, 'rank_math_robots', true );
            if ( is_string( $robots ) ) { $robots = maybe_unserialize( $robots ); }
            if ( is_array( $robots ) ) {
                $flat = array_map( 'strval', array_values( $robots ) );
                $index = in_array( 'noindex', $flat, true ) ? 'noindex' : ( in_array( 'index', $flat, true ) ? 'index' : '' );
                $follow = in_array( 'nofollow', $flat, true ) ? 'nofollow' : ( in_array( 'follow', $flat, true ) ? 'follow' : '' );
                foreach ( array( Meta_Repository::ROBOTS_INDEX=>$index, Meta_Repository::ROBOTS_FOLLOW=>$follow ) as $key=>$value ) {
                    if ( '' === $value ) { continue; }
                    if ( ! $overwrite && '' !== (string) get_post_meta( $post_id, $key, true ) ) { $stats['skipped']++; continue; }
                    update_post_meta( $post_id, $key, $value );
                    $stats['fields']++;
                }
            }
        }
        update_option( 'aikey_rank_math_imported_at', current_time( 'mysql', true ), false );
        return $stats;
    }

    private static function resolve( string $value, \WP_Post $post ): string {
        if ( '' === $value || false === strpos( $value, '%' ) ) { return $value; }
        $excerpt = has_excerpt( $post ) ? $post->post_excerpt : wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
        if ( function_exists( 'mb_substr' ) ) { $excerpt = mb_substr( trim( $excerpt ), 0, 160, 'UTF-8' ); } else { $excerpt = substr( trim( $excerpt ), 0, 160 ); }
        $replacements = array(
            '%title%' => get_the_title( $post ),
            '%sep%' => '-',
            '%sitename%' => get_bloginfo( 'name' ),
            '%excerpt%' => $excerpt,
            '%date%' => get_the_date( '', $post ),
            '%currentyear%' => wp_date( 'Y' ),
            '%currentmonth%' => wp_date( 'm' ),
        );
        return trim( preg_replace( '/\s+/u', ' ', strtr( $value, $replacements ) ) ?: strtr( $value, $replacements ) );
    }
}
