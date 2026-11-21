<?php
namespace AIKEY\SeoGeo\SEO\Redirects;

use AIKEY\SeoGeo\Core\Database;
use AIKEY\SeoGeo\Core\Conflict_Detector;
use AIKEY\SeoGeo\Core\Module_Interface;
use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\Support\Utils;

defined( 'ABSPATH' ) || exit;

final class Redirect_Module implements Module_Interface {
    public function id(): string { return 'redirections'; }

    public function register(): void {
        if ( Settings::module_enabled( 'redirections' ) ) {
            add_action( 'template_redirect', array( $this, 'maybe_redirect' ), -20 );
        }
        if ( Settings::module_enabled( 'monitor_404' ) ) {
            add_action( 'template_redirect', array( $this, 'log_404' ), 1000 );
        }
    }

    public function maybe_redirect(): void {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! Conflict_Detector::frontend_allowed() ) { return; }
        $path = Utils::current_path();
        // The rule editor forbids / as a source; the homepage never needs a redirect lookup.
        if ( '/' === $path ) { return; }
        if ( in_array( $path, array( '/sitemap.xml','/sitemap_index.xml','/aikey-sitemap-home.xml','/baidu-sitemap.xml','/llms.txt','/aikey-indexnow-key.txt' ), true ) || preg_match( '#^/aikey-sitemap-(?:post|tax)-[^/]+-[0-9]+\.xml$#', $path ) ) { return; }
        global $wpdb;
        $table = Database::table( 'redirects' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_path = %s AND enabled = 1 LIMIT 1", $path ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $row ) { return; }
        $status = (int) $row->status;
        if ( 410 === $status ) {
            $now = current_time( 'mysql', true );
            $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_hit = %s WHERE id = %d", $now, (int) $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            status_header( 410 );
            nocache_headers();
            exit;
        }
        if ( ! in_array( $status, array( 301,302,307,308 ), true ) ) { $status = 301; }
        $target = trim( (string) $row->target_url );
        if ( '' === $target ) { return; }

        // Defend at runtime as well as at save time. This protects upgraded sites from
        // legacy/manually altered rules that may already contain a self-loop or cycle.
        $local_target = self::local_target_path( $target );
        if ( null !== $local_target && ( $local_target === $path || self::would_create_cycle( $path, $local_target ) ) ) { return; }

        $now = current_time( 'mysql', true );
        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_hit = %s WHERE id = %d", $now, (int) $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( str_starts_with( $target, '/' ) ) { $target = home_url( $target ); }
        wp_redirect( esc_url_raw( $target ), $status, 'Aikeyboost SEO&GEO' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
        exit;
    }

    public function log_404(): void {
        if ( ! is_404() || is_admin() || wp_doing_ajax() || wp_doing_cron() ) { return; }
        $path = Utils::current_path();
        if ( strlen( $path ) > 191 || str_contains( $path, '/wp-admin' ) || self::looks_like_probe( $path ) ) { return; }
        $referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
        $referrer = substr( $referrer, 0, 1000 );
        $now = current_time( 'mysql', true );
        global $wpdb;
        $table = Database::table( '404_logs' );
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (path, referrer, hits, first_seen, last_seen) VALUES (%s,%s,1,%s,%s)
             ON DUPLICATE KEY UPDATE hits = hits + 1, referrer = VALUES(referrer), last_seen = VALUES(last_seen)",
            $path, $referrer, $now, $now
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function add( string $source, string $target, int $status = 301 ): bool|\WP_Error {
        if ( ! in_array( $status, array( 301,302,307,308,410 ), true ) ) { return new \WP_Error( 'invalid_status', '无效状态码。' ); }
        $source = Utils::normalize_path( $source );
        if ( '/' === $source ) { return new \WP_Error( 'invalid_source', '不能将网站首页设置为重定向来源。' ); }
        if ( 410 !== $status ) {
            $target = trim( $target );
            if ( str_starts_with( $target, '/' ) ) { $target = Utils::normalize_path( $target ); }
            else { $target = esc_url_raw( $target, array( 'http','https' ) ); }
            if ( '' === $target ) { return new \WP_Error( 'invalid_target', '请填写有效目标地址。' ); }
        } else { $target = ''; }

        if ( 410 !== $status ) {
            $local_target = self::local_target_path( $target );
            if ( null !== $local_target ) {
                if ( $local_target === $source ) {
                    return new \WP_Error( 'redirect_self', '重定向来源和目标不能是同一个站内地址。' );
                }
                if ( self::would_create_cycle( $source, $local_target ) ) {
                    return new \WP_Error( 'redirect_cycle', '该规则会形成循环重定向，请更换目标地址。' );
                }
            }
        }

        global $wpdb;
        $table = Database::table( 'redirects' );
        $now = current_time( 'mysql', true );
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_path=%s", $source ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $existing ) {
            $ok = $wpdb->update( $table, array( 'target_url'=>$target,'status'=>$status,'enabled'=>1,'updated_at'=>$now ), array( 'id'=>(int) $existing ), array( '%s','%d','%d','%s' ), array( '%d' ) );
            if ( false !== $ok ) { do_action( 'aikey_seo_geo_redirects_changed' ); }
            return false !== $ok;
        }
        $ok = $wpdb->insert( $table, array( 'source_path'=>$source,'target_url'=>$target,'status'=>$status,'enabled'=>1,'hits'=>0,'created_at'=>$now,'updated_at'=>$now ), array( '%s','%s','%d','%d','%d','%s','%s' ) );
        if ( false !== $ok ) { do_action( 'aikey_seo_geo_redirects_changed' ); }
        return false !== $ok;
    }

    public static function delete( int $id ): void {
        global $wpdb;
        $deleted = $wpdb->delete( Database::table( 'redirects' ), array( 'id'=>$id ), array( '%d' ) );
        if ( false !== $deleted && 0 < (int) $deleted ) { do_action( 'aikey_seo_geo_redirects_changed' ); }
    }

    /** @return string[] */
    public static function enabled_source_paths(): array {
        global $wpdb;
        $table = Database::table( 'redirects' );
        $paths = $wpdb->get_col( "SELECT source_path FROM {$table} WHERE enabled=1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return array_values( array_unique( array_filter( array_map( static fn( $path ) => Utils::normalize_path( (string) $path ), is_array( $paths ) ? $paths : array() ) ) ) );
    }

    public static function list_redirects( int $limit = 100 ): array {
        return self::list_redirects_page( 1, $limit );
    }

    public static function count_redirects(): int {
        global $wpdb;
        $table = Database::table( 'redirects' );
        return max( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function list_redirects_page( int $page = 1, int $per_page = 10 ): array {
        global $wpdb;
        $table = Database::table( 'redirects' );
        $page = max( 1, $page );
        $per_page = min( 100, max( 1, $per_page ) );
        $offset = ( $page - 1 ) * $per_page;
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function count_404(): int {
        global $wpdb;
        $table = Database::table( '404_logs' );
        return max( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function list_404_page( int $page = 1, int $per_page = 10 ): array {
        global $wpdb;
        $table = Database::table( '404_logs' );
        $page = max( 1, $page );
        $per_page = min( 500, max( 1, $per_page ) );
        $offset = ( $page - 1 ) * $per_page;
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY last_seen DESC, id DESC LIMIT %d OFFSET %d", $per_page, $offset ) ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function list_404( int $limit = 100 ): array {
        $limit = min( 500, max( 1, $limit ) );
        return self::list_404_page( 1, $limit );
    }

    public static function bulk_add( array $sources, string $target, int $status = 301 ): array {
        $sources = array_values( array_unique( array_filter( array_map( static fn( $path ) => Utils::normalize_path( (string) $path ), $sources ) ) ) );
        $sources = array_slice( $sources, 0, 200 );
        $stats = array( 'success'=>0, 'skipped'=>0, 'failed'=>0 );
        $handled = array();
        foreach ( $sources as $source ) {
            if ( '/' === $source ) { $stats['skipped']++; continue; }
            $result = self::add( $source, $target, $status );
            if ( is_wp_error( $result ) || false === $result ) { $stats['failed']++; continue; }
            $stats['success']++;
            $handled[] = $source;
        }
        if ( $handled ) { self::delete_404_paths( $handled ); }
        return $stats;
    }

    public static function smart_redirect_404( int $limit = 200 ): array {
        $limit = min( 300, max( 1, $limit ) );
        $stats = array( 'success'=>0, 'skipped'=>0, 'probes'=>0, 'failed'=>0 );
        $handled = array();
        foreach ( self::list_404( $limit ) as $row ) {
            $source = Utils::normalize_path( (string) $row->path );
            if ( self::looks_like_probe( $source ) ) { $stats['probes']++; continue; }
            if ( self::has_redirect( $source ) ) { $stats['skipped']++; continue; }
            $target = self::suggest_target( $source );
            if ( '' === $target ) { $stats['skipped']++; continue; }
            $result = self::add( $source, $target, 301 );
            if ( is_wp_error( $result ) || false === $result ) { $stats['failed']++; continue; }
            $stats['success']++;
            $handled[] = $source;
        }
        if ( $handled ) { self::delete_404_paths( $handled ); }
        return $stats;
    }

    public static function has_redirect( string $source ): bool {
        global $wpdb;
        $table = Database::table( 'redirects' );
        $source = Utils::normalize_path( $source );
        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_path=%s AND enabled=1 LIMIT 1", $source ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    private static function local_target_path( string $target ): ?string {
        $target = trim( $target );
        if ( '' === $target ) { return null; }
        if ( str_starts_with( $target, '/' ) ) { return Utils::normalize_path( $target ); }

        $scheme = strtolower( (string) wp_parse_url( $target, PHP_URL_SCHEME ) );
        $host = strtolower( (string) wp_parse_url( $target, PHP_URL_HOST ) );
        $home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || $host !== $home_host ) { return null; }
        return Utils::normalize_path( $target );
    }

    private static function would_create_cycle( string $source, string $target_path ): bool {
        global $wpdb;
        $table = Database::table( 'redirects' );
        $current = Utils::normalize_path( $target_path );
        $visited = array();

        // Follow the existing local redirect chain. Twenty hops is far beyond a healthy
        // redirect chain; treat a longer/looping chain as unsafe rather than creating it.
        for ( $hop = 0; $hop < 20; $hop++ ) {
            if ( $current === $source || isset( $visited[ $current ] ) ) { return true; }
            $visited[ $current ] = true;
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT target_url,status FROM {$table} WHERE source_path=%s AND enabled=1 LIMIT 1",
                $current
            ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( ! $row || 410 === (int) $row->status || '' === trim( (string) $row->target_url ) ) { return false; }
            $next = self::local_target_path( (string) $row->target_url );
            if ( null === $next ) { return false; }
            $current = $next;
        }
        return true;
    }

    private static function suggest_target( string $source ): string {
        $path = trim( Utils::normalize_path( $source ), '/' );
        if ( '' === $path ) { return ''; }
        $types = Utils::public_post_types();

        $candidates = array( $path );
        $clean = preg_replace( '#/(?:feed|amp|index(?:\\.html?)?)$#i', '', $path );
        if ( is_string( $clean ) && '' !== $clean && $clean !== $path ) { $candidates[] = $clean; }
        $clean_ext = preg_replace( '#\\.(?:html?|shtml)$#i', '', $path );
        if ( is_string( $clean_ext ) && '' !== $clean_ext && $clean_ext !== $path ) { $candidates[] = $clean_ext; }

        foreach ( array_unique( $candidates ) as $candidate ) {
            $post = get_page_by_path( $candidate, OBJECT, $types );
            if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
                $permalink = get_permalink( $post );
                if ( $permalink && Utils::normalize_path( $permalink ) !== Utils::normalize_path( $source ) ) { return $permalink; }
            }
        }

        $slug = sanitize_title( basename( (string) end( $candidates ) ) );
        if ( strlen( $slug ) < 3 || in_array( $slug, array( 'home','index','page','post','blog','news' ), true ) ) { return ''; }
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $params = array_merge( array( $slug, 'publish' ), $types );
        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name=%s AND post_status=%s AND post_type IN ({$placeholders}) ORDER BY ID DESC LIMIT 2",
            $params
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( 1 !== count( $ids ) ) { return ''; }
        $permalink = get_permalink( (int) $ids[0] );
        return $permalink && Utils::normalize_path( $permalink ) !== Utils::normalize_path( $source ) ? $permalink : '';
    }

    private static function looks_like_probe( string $path ): bool {
        $lower = strtolower( rawurldecode( $path ) );
        if ( str_contains( $lower, ':' ) || str_contains( $lower, '\\' ) || str_starts_with( $lower, '/.' ) ) { return true; }
        return (bool) preg_match( '#(?:^|/)(?:wp|wp-admin|wp-login|wp-content|wp-includes|wordpress|phpmyadmin|pma|cgi-bin|vendor|actuator|server-status|sdk|backup|backups|testing|test|newsite|check-host(?:\\.net)?|evox)(?:/|$)|\\.(?:php|asp|aspx|jsp|env|ini|sql|bak)(?:$|/)#i', $lower );
    }

    public static function delete_404_paths( array $paths ): int {
        global $wpdb;
        $table = Database::table( '404_logs' );
        $paths = array_values( array_unique( array_filter( array_map( static fn( $path ) => Utils::normalize_path( (string) $path ), $paths ) ) ) );
        if ( ! $paths ) { return 0; }
        $paths = array_slice( $paths, 0, 300 );
        $placeholders = implode( ',', array_fill( 0, count( $paths ), '%s' ) );
        $sql = $wpdb->prepare( "DELETE FROM {$table} WHERE path IN ({$placeholders})", $paths ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function clear_404(): void {
        global $wpdb;
        $table = Database::table( '404_logs' );
        $wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
