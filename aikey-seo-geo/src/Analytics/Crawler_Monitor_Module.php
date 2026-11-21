<?php
namespace AIKEY\SeoGeo\Analytics;

use AIKEY\SeoGeo\Core\Database;
use AIKEY\SeoGeo\Core\Module_Interface;
use AIKEY\SeoGeo\Integrations\Engine_Registry;
use AIKEY\SeoGeo\Support\Utils;

defined( 'ABSPATH' ) || exit;

final class Crawler_Monitor_Module implements Module_Interface {
    public function id(): string { return 'crawler_monitor'; }
    public function register(): void { add_action( 'template_redirect', array( $this, 'track' ), 5 ); }

    public function track(): void {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || empty( $_SERVER['HTTP_USER_AGENT'] ) ) { return; }
        $ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
        $match = Engine_Registry::detect_crawler( $ua );
        if ( empty( $match['label'] ) ) { return; }
        $path = Utils::current_path();
        $now = current_time( 'mysql', true );
        global $wpdb;
        $table = Database::table( 'crawler_logs' );
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (source,crawler_type,path,hits,first_seen,last_seen) VALUES (%s,%s,%s,1,%s,%s)
             ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen)",
            (string) $match['label'], (string) $match['group'], $path, $now, $now
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function totals(): array {
        global $wpdb;
        $table = Database::table( 'crawler_logs' );
        return $wpdb->get_results( "SELECT source,crawler_type,SUM(hits) hits,MAX(last_seen) last_seen FROM {$table} GROUP BY source,crawler_type ORDER BY hits DESC" ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function rows( int $limit = 100 ): array {
        global $wpdb;
        $table = Database::table( 'crawler_logs' );
        $limit = min( 500, max( 1, $limit ) );
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY last_seen DESC LIMIT %d", $limit ) ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
