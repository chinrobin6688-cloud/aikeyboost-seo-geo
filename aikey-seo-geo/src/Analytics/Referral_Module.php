<?php
namespace AIKEY\SeoGeo\Analytics;

use AIKEY\SeoGeo\Core\Database;
use AIKEY\SeoGeo\Core\Module_Interface;
use AIKEY\SeoGeo\Integrations\Engine_Registry;
use AIKEY\SeoGeo\Support\Utils;

defined( 'ABSPATH' ) || exit;

final class Referral_Module implements Module_Interface {
    public function id(): string { return 'referrals'; }
    public function register(): void { add_action( 'template_redirect', array( $this, 'track' ), 900 ); }

    public function track(): void {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || empty( $_SERVER['HTTP_REFERER'] ) ) { return; }
        $referer = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
        $host = strtolower( (string) wp_parse_url( $referer, PHP_URL_HOST ) );
        if ( '' === $host ) { return; }
        $source = self::source_from_host( $host );
        if ( '' === $source ) { return; }
        $path = Utils::current_path();
        $now = current_time( 'mysql', true );
        global $wpdb;
        $table = Database::table( 'referrals' );
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (source,path,hits,first_seen,last_seen) VALUES (%s,%s,1,%s,%s)
             ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen)",
            $source, $path, $now, $now
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function source_from_host( string $host ): string {
        return Engine_Registry::source_from_host( $host );
    }

    public static function rows( int $limit = 100 ): array {
        global $wpdb;
        $table = Database::table( 'referrals' );
        $limit = min( 500, max( 1, $limit ) );
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY last_seen DESC LIMIT %d", $limit ) ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function totals(): array {
        global $wpdb;
        $table = Database::table( 'referrals' );
        return $wpdb->get_results( "SELECT source, SUM(hits) hits FROM {$table} GROUP BY source ORDER BY hits DESC" ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
