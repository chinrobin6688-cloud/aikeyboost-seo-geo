<?php
namespace AIKEY\SeoGeo\Integrations;

use AIKEY\SeoGeo\Core\Database;

defined( 'ABSPATH' ) || exit;

final class Submission_Log {
    public static function add( string $channel, int $count, int $status_code, bool $success, string $message = '', string $sample_url = '' ): void {
        global $wpdb;
        $wpdb->insert(
            Database::table( 'submission_logs' ),
            array(
                'channel'     => sanitize_key( $channel ),
                'item_count'  => max( 0, $count ),
                'status_code' => max( 0, $status_code ),
                'success'     => $success ? 1 : 0,
                'message'     => sanitize_text_field( $message ),
                'sample_url'  => esc_url_raw( $sample_url, array( 'http', 'https' ) ),
                'created_at'  => current_time( 'mysql', true ),
            ),
            array( '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
        );
        self::prune();
    }

    public static function recent( int $limit = 50 ): array {
        global $wpdb;
        $limit = min( 200, max( 1, $limit ) );
        $table = Database::table( 'submission_logs' );
        return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function totals(): array {
        global $wpdb;
        $table = Database::table( 'submission_logs' );
        return (array) $wpdb->get_results( "SELECT channel, SUM(item_count) AS item_count, MAX(created_at) AS last_submitted, SUM(success=1) AS successes, SUM(success=0) AS failures FROM {$table} GROUP BY channel ORDER BY channel ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function clear(): void {
        global $wpdb;
        $table = Database::table( 'submission_logs' );
        $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    private static function prune(): void {
        global $wpdb;
        $table = Database::table( 'submission_logs' );
        $max_id = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $max_id > 5000 ) {
            $cutoff = $max_id - 5000;
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id < %d", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
    }
}
