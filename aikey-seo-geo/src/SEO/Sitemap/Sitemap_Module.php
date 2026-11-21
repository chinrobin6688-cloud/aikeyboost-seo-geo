<?php
namespace AIKEY\SeoGeo\SEO\Sitemap;

use AIKEY\SeoGeo\Core\Conflict_Detector;
use AIKEY\SeoGeo\Core\Module_Interface;
use AIKEY\SeoGeo\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Sitemap_Module implements Module_Interface {
    private const DIRECT_URL_LIMIT = 5000;

    public function id(): string { return 'sitemap'; }

    public function register(): void {
        add_action( 'init', array( $this, 'rewrites' ) );
        add_filter( 'query_vars', array( $this, 'query_vars' ) );
        add_action( 'template_redirect', array( $this, 'render' ), 0 );
        add_filter( 'wp_sitemaps_enabled', array( $this, 'disable_core_sitemap' ) );
        add_filter( 'robots_txt', array( $this, 'robots' ), 9999, 2 );
        // Domain/home URL changes alter every canonical Sitemap URL and the
        // physical robots.txt Sitemap directive. Refresh lazily and rewrite the
        // physical file only when it already exists and is safely writable.
        add_action( 'update_option_home', array( $this, 'site_url_changed' ), 10, 3 );
        add_action( 'update_option_siteurl', array( $this, 'site_url_changed' ), 10, 3 );
        Sitemap_Cache::register_hooks();
    }

    public function site_url_changed( mixed $old_value = null, mixed $new_value = null, string $option = '' ): void {
        if ( (string) $old_value === (string) $new_value ) { return; }
        Sitemap_Cache::refresh();
        update_option( 'aikey_seo_geo_flush_rewrite', '1', false );
        try {
            if ( Robots_File_Manager::exists() && Robots_File_Manager::writable() ) {
                Robots_File_Manager::sync_managed_block();
            }
        } catch ( \Throwable $error ) {
            // Domain changes must never fail merely because the web-root file is
            // not writable. Health diagnostics will surface any stale directive.
        }
    }

    public function rewrites(): void {
        if ( ! Conflict_Detector::sitemap_allowed() ) { return; }
        add_rewrite_rule( '^sitemap\.xml$', 'index.php?aikey_sitemap=main', 'top' );
        // Backward compatibility: previously submitted URL remains a 200 XML endpoint.
        add_rewrite_rule( '^sitemap_index\.xml$', 'index.php?aikey_sitemap=legacy-main', 'top' );
        add_rewrite_rule( '^aikey-sitemap-home\.xml$', 'index.php?aikey_sitemap=home', 'top' );
        add_rewrite_rule( '^aikey-sitemap-(post|tax)-([^/]+)-([0-9]+)\.xml$', 'index.php?aikey_sitemap=$matches[1]&aikey_sitemap_name=$matches[2]&aikey_sitemap_page=$matches[3]', 'top' );
        add_rewrite_rule( '^baidu-sitemap\.xml$', 'index.php?aikey_sitemap=baidu', 'top' );
    }

    public function query_vars( array $vars ): array {
        $vars[] = 'aikey_sitemap';
        $vars[] = 'aikey_sitemap_name';
        $vars[] = 'aikey_sitemap_page';
        return $vars;
    }

    public function disable_core_sitemap( bool $enabled ): bool {
        if ( ! self::is_active() ) { return $enabled; }
        return ! empty( Settings::section_get( 'sitemap', 'disable_core_sitemap', true ) ) ? false : $enabled;
    }

    public function robots( string $output, bool $public ): string {
        if ( ! $public || ! self::is_active() ) { return $output; }
        try {
            if ( Robots_File_Manager::exists() ) { return $output; }
        } catch ( \Throwable $error ) {
            // Keep WordPress fallback available if physical-file inspection fails.
        }
        return self::automatic_robots( $output );
    }

    /**
     * Preserve arbitrary administrator/third-party rules, remove only plugin-known
     * legacy Sitemap declarations, then advertise the single recommended main URL.
     */
    public static function automatic_robots( string $output ): string {
        $output = str_replace( array( "\r\n", "\r" ), "\n", trim( $output ) );
        if ( ! preg_match( '/^User-agent\s*:/mi', $output ) ) {
            $output = "User-agent: *\nAllow: /\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php";
        } elseif ( ! preg_match( '/^User-agent\s*:\s*\*\s*$/mi', $output ) ) {
            $output = rtrim( $output ) . "\n\nUser-agent: *\nAllow: /\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php";
        }

        $main = self::main_url();
        $output = self::strip_managed_sitemap_lines( $output );
        $output = rtrim( $output ) . "\nSitemap: " . $main;
        return rtrim( $output ) . "\n";
    }

    public function render(): void {
        $kind = (string) get_query_var( 'aikey_sitemap' );
        if ( '' === $kind ) { return; }
        if ( ! self::is_active() ) {
            status_header( 404 );
            exit;
        }

        $name = sanitize_key( (string) get_query_var( 'aikey_sitemap_name' ) );
        $page = max( 1, absint( get_query_var( 'aikey_sitemap_page' ) ) );
        $buffer_level = ob_get_level();
        ob_start();
        try {
            $payload = $this->payload( $kind, $name, $page );
            while ( ob_get_level() > $buffer_level ) { ob_end_clean(); }
        } catch ( \Throwable $error ) {
            while ( ob_get_level() > $buffer_level ) { ob_end_clean(); }
            status_header( 500 );
            header( 'Content-Type: application/xml; charset=UTF-8' );
            header( 'X-Robots-Tag: noindex, follow', true );
            echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
            exit;
        }
        if ( null === $payload ) {
            status_header( 404 );
            exit;
        }

        $this->send_headers( $payload );
        if ( $this->not_modified( $payload ) ) {
            status_header( 304 );
            exit;
        }
        status_header( 200 );
        echo (string) $payload['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML is escaped at construction.
        exit;
    }

    public static function main_url(): string { return home_url( '/sitemap.xml' ); }
    /** Kept as an internal compatibility method; callers now receive the recommended main URL. */
    public static function index_url(): string { return self::main_url(); }
    public static function legacy_index_url(): string { return home_url( '/sitemap_index.xml' ); }
    public static function baidu_direct_url(): string { return home_url( '/baidu-sitemap.xml' ); }

    public static function is_active(): bool {
        return Settings::module_enabled( 'sitemap' )
            && (bool) get_option( 'blog_public', true )
            && Conflict_Detector::sitemap_allowed();
    }

    public static function core_sitemap_is_disabled(): bool {
        return self::is_active() && ! empty( Settings::section_get( 'sitemap', 'disable_core_sitemap', true ) );
    }


    public static function published_indexable_count(): int {
        $stats = Sitemap_Cache::stats();
        if ( isset( $stats['total'] ) ) { return max( 0, (int) $stats['total'] ); }
        // Avoid a full generation just to render a normal admin page. This count is
        // a cheap published-candidate estimate until the main Sitemap is generated.
        global $wpdb;
        $types = Sitemap_Content::post_types();
        if ( ! $types ) { return 0; }
        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $params = array_merge( array( \AIKEY\SeoGeo\SEO\Meta\Meta_Repository::ROBOTS_INDEX ), $types );
        $sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key=%s WHERE p.post_status='publish' AND p.post_password='' AND p.post_type IN ({$placeholders}) AND (pm.meta_value IS NULL OR pm.meta_value <> 'noindex')";
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /** @return array<string,mixed> */
    public static function runtime_stats(): array { return Sitemap_Cache::stats(); }
    public static function main_cache_ready(): bool { return Sitemap_Cache::is_cached( 'main' ); }

    /** @return string[] */
    public static function manual_submission_urls( int $limit = 100 ): array {
        $limit = min( 500, max( 1, $limit ) );
        if ( ! self::is_active() ) { return array(); }
        $content = new Sitemap_Content();
        $entries = $content->direct_entries( $limit, min( 500, self::child_page_size() ) );
        $urls = array();
        foreach ( $entries as $entry ) {
            $url = esc_url_raw( (string) ( $entry['url'] ?? '' ), array( 'http', 'https' ) );
            if ( '' !== $url ) { $urls[] = $url; }
        }
        return array_values( array_unique( $urls ) );
    }

    /**
     * Public deterministic generator used by release tests. It does not send HTTP
     * headers and therefore cannot terminate WordPress/admin requests.
     *
     * @return array<string,mixed>|null
     */
    public function build_payload( string $kind = 'main', string $name = '', int $page = 1 ): ?array {
        return $this->payload( $kind, sanitize_key( $name ), max( 1, $page ) );
    }

    /** @return array<string,mixed>|null */
    private function payload( string $kind, string $name, int $page ): ?array {
        if ( in_array( $kind, array( 'main', 'legacy-main' ), true ) ) {
            $cached = Sitemap_Cache::get( 'main' );
            if ( null !== $cached ) { return $cached; }
            return $this->build_main_payload();
        }
        if ( 'home' === $kind ) { return $this->cached_child( 'home', '', 1 ); }
        if ( 'post' === $kind ) { return $this->cached_child( 'post', $name, $page ); }
        if ( 'tax' === $kind ) { return $this->cached_child( 'tax', $name, $page ); }
        if ( 'baidu' === $kind ) {
            $cached = Sitemap_Cache::get( 'baidu' );
            if ( null !== $cached ) { return $cached; }
            $content = new Sitemap_Content();
            $limit = min( 50000, max( 100, (int) Settings::section_get( 'sitemap', 'baidu_direct_limit', 50000 ) ) );
            $entries = $content->direct_entries( $limit, min( 1000, self::child_page_size() ) );
            $payload = $this->make_baidu_payload( $entries );
            Sitemap_Cache::set( 'baidu', $payload );
            return $payload;
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function build_main_payload(): array {
        $content = new Sitemap_Content();
        $scan_size = min( 1000, self::child_page_size() );
        $probe = $content->collect_until( self::DIRECT_URL_LIMIT, $scan_size );
        $generated_at = time();

        if ( empty( $probe['overflow'] ) ) {
            $payload = $this->make_urlset_payload( $probe['entries'] );
            $stats = $probe['stats'];
            $stats['mode'] = 'urlset';
            $stats['generated_at'] = $generated_at;
            $stats['cacheable'] = strlen( (string) $payload['body'] ) <= 4000000;
            Sitemap_Cache::set_stats( $stats );
            Sitemap_Cache::set( 'main', $payload );
            return $payload;
        }

        // Large sites use a count/index pass without carrying every child entry
        // in memory. Child XML is generated and cached only when that child URL is
        // requested, which keeps peak memory bounded by roughly one child chunk.
        $index_content = new Sitemap_Content();
        $index = $index_content->build_index( self::child_page_size() );
        if ( empty( $index['children'] ) ) {
            // Defensive non-empty fallback: never emit <sitemapindex></sitemapindex>
            // if the direct probe already proved that public URLs exist.
            $payload = $this->make_urlset_payload( array_slice( $probe['entries'], 0, self::DIRECT_URL_LIMIT ) );
            $stats = $probe['stats'];
            $stats['mode'] = 'urlset-fallback';
        } else {
            $payload = $this->make_index_payload( $index['children'], (string) $index['lastmod'] );
            $stats = $index['stats'];
            $stats['mode'] = 'index';
        }
        $stats['generated_at'] = $generated_at;
        $stats['cacheable'] = strlen( (string) $payload['body'] ) <= 4000000;
        Sitemap_Cache::set_stats( $stats );
        Sitemap_Cache::set( 'main', $payload );
        return $payload;
    }

    /** @return array<string,mixed>|null */
    private function cached_child( string $kind, string $name, int $page ): ?array {
        $key = $kind . '-' . $name . '-' . $page;
        $cached = Sitemap_Cache::get( $key );
        if ( null !== $cached ) { return $cached; }
        $content = new Sitemap_Content();
        if ( 'home' === $kind ) {
            $home = $content->home_entry();
            $entries = null === $home ? array() : array( $home );
        } elseif ( 'post' === $kind ) {
            if ( ! in_array( $name, Sitemap_Content::post_types(), true ) ) { return null; }
            $entries = $content->post_entries( $name, $page, self::child_page_size() );
        } else {
            if ( ! in_array( $name, Sitemap_Content::taxonomies(), true ) ) { return null; }
            $entries = $content->term_entries( $name, $page, self::child_page_size() );
        }
        if ( ! $entries ) { return null; }

        // In index mode the main pass records only cross-child collisions. Keep
        // the first canonical owner and filter later children without retaining
        // a full-site URL map in every child request.
        $owners = Sitemap_Cache::duplicate_owners();
        if ( $owners ) {
            $entries = array_values( array_filter( $entries, static function ( array $entry ) use ( $owners, $key ): bool {
                $fingerprint = Sitemap_Content::url_fingerprint( (string) ( $entry['url'] ?? '' ) );
                return '' !== $fingerprint && ( ! isset( $owners[ $fingerprint ] ) || $owners[ $fingerprint ] === sanitize_key( $key ) );
            } ) );
        }
        if ( ! $entries ) { return null; }
        $payload = $this->make_urlset_payload( $entries );
        Sitemap_Cache::set( $key, $payload );
        return $payload;
    }


    /**
     * Keep the legacy Baidu direct endpoint compact and dependency-free. It is a
     * compatibility URL, not the recommended primary Sitemap. Images are omitted
     * and output is capped below 10 MB while respecting the 50,000 URL ceiling.
     *
     * @param array<int,array<string,mixed>> $entries
     * @return array<string,mixed>
     */
    private function make_baidu_payload( array $entries ): array {
        $max_bytes = 9500000;
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "
";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "
";
        $latest = '';
        $seen = array();
        $count = 0;
        foreach ( $entries as $entry ) {
            if ( $count >= 50000 ) { break; }
            $url = (string) ( $entry['url'] ?? '' );
            if ( '' === $url || isset( $seen[ $url ] ) ) { continue; }
            $lastmod = (string) ( $entry['lastmod'] ?? '' );
            $row = '<url><loc>' . esc_xml( $url ) . '</loc>';
            if ( '' !== $lastmod ) { $row .= '<lastmod>' . esc_xml( $lastmod ) . '</lastmod>'; }
            $row .= '</url>' . "
";
            if ( strlen( $xml ) + strlen( $row ) + 9 > $max_bytes ) { break; }
            $seen[ $url ] = true;
            $xml .= $row;
            $latest = self::newest( $latest, $lastmod );
            $count++;
        }
        $xml .= '</urlset>';
        return array( 'body'=>$xml, 'lastmod'=>$latest, 'generated_at'=>time() );
    }

    /** @param array<int,array<string,mixed>> $entries @return array<string,mixed> */
    private function make_urlset_payload( array $entries ): array {
        // Primary Sitemap is deliberately URL-only. Keep the protocol simple:
        // one canonical front-end URL per <url>, plus its real lastmod when known.
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        $latest = '';
        $seen = array();
        foreach ( $entries as $entry ) {
            $url = (string) ( $entry['url'] ?? '' );
            if ( '' === $url ) { continue; }
            $fingerprint = Sitemap_Content::url_fingerprint( $url );
            if ( '' === $fingerprint || isset( $seen[ $fingerprint ] ) ) { continue; }
            $seen[ $fingerprint ] = true;
            $lastmod = (string) ( $entry['lastmod'] ?? '' );
            $latest = self::newest( $latest, $lastmod );
            $xml .= '<url><loc>' . esc_xml( $url ) . '</loc>';
            if ( '' !== $lastmod ) { $xml .= '<lastmod>' . esc_xml( $lastmod ) . '</lastmod>'; }
            $xml .= '</url>' . "\n";
        }
        $xml .= '</urlset>';
        return array( 'body'=>$xml, 'lastmod'=>$latest, 'generated_at'=>time() );
    }

    /** @param array<int,array{url:string,lastmod:string}> $children @return array<string,mixed> */
    private function make_index_payload( array $children, string $latest ): array {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ( $children as $child ) {
            $url = (string) ( $child['url'] ?? '' );
            if ( '' === $url ) { continue; }
            $lastmod = (string) ( $child['lastmod'] ?? '' );
            $xml .= '<sitemap><loc>' . esc_xml( $url ) . '</loc>';
            if ( '' !== $lastmod ) { $xml .= '<lastmod>' . esc_xml( $lastmod ) . '</lastmod>'; }
            $xml .= '</sitemap>' . "\n";
        }
        $xml .= '</sitemapindex>';
        return array( 'body'=>$xml, 'lastmod'=>$latest, 'generated_at'=>time() );
    }

    /**
     * Remove Sitemap declarations owned by this plugin across domain migrations,
     * plus WordPress core's default endpoint while AI企帮 owns robots.txt. Other
     * custom Sitemap URLs are preserved.
     */
    public static function strip_managed_sitemap_lines( string $text ): string {
        $text = str_replace( array( "\r\n", "\r" ), "\n", $text );
        $lines = preg_split( '/\n/', $text );
        $kept = array();
        foreach ( is_array( $lines ) ? $lines : array() as $line ) {
            if ( ! preg_match( '/^[\t ]*Sitemap[\t ]*:[\t ]*(\S+)[\t ]*$/i', (string) $line, $match ) ) {
                $kept[] = (string) $line;
                continue;
            }
            $url = trim( (string) $match[1] );
            $path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
            $path = '/' . ltrim( $path, '/' );
            if ( in_array( $path, array( '/sitemap.xml', '/sitemap_index.xml', '/baidu-sitemap.xml', '/wp-sitemap.xml' ), true ) ) {
                continue;
            }
            $kept[] = (string) $line;
        }
        return trim( implode( "\n", $kept ) );
    }

    /** @param array<string,mixed> $payload */
    private function send_headers( array $payload ): void {
        header( 'Content-Type: application/xml; charset=UTF-8' );
        header( 'X-Robots-Tag: noindex, follow', true );
        header( 'Cache-Control: public, max-age=300, must-revalidate', true );
        header( 'ETag: ' . $this->etag( $payload ), true );
        $timestamp = $this->last_modified_timestamp( $payload );
        if ( $timestamp > 0 ) { header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $timestamp ) . ' GMT', true ); }
    }

    /** @param array<string,mixed> $payload */
    private function not_modified( array $payload ): bool {
        $etag = $this->etag( $payload );
        $if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( wp_unslash( (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '';
        if ( '' !== $if_none_match ) { return hash_equals( $etag, $if_none_match ); }
        $if_modified_since = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? trim( wp_unslash( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) : '';
        if ( '' === $if_modified_since ) { return false; }
        $client_time = strtotime( $if_modified_since );
        $server_time = $this->last_modified_timestamp( $payload );
        return false !== $client_time && $server_time > 0 && $server_time <= $client_time;
    }

    /** @param array<string,mixed> $payload */
    private function etag( array $payload ): string { return '"' . hash( 'sha256', (string) ( $payload['body'] ?? '' ) ) . '"'; }

    /** @param array<string,mixed> $payload */
    private function last_modified_timestamp( array $payload ): int {
        $lastmod = trim( (string) ( $payload['lastmod'] ?? '' ) );
        if ( '' !== $lastmod ) {
            $time = strtotime( $lastmod );
            if ( false !== $time ) { return $time; }
        }
        return max( 0, (int) ( $payload['generated_at'] ?? 0 ) );
    }

    private static function child_page_size(): int {
        return min( 5000, max( 100, (int) Settings::section_get( 'sitemap', 'max_urls', 5000 ) ) );
    }

    private static function newest( string $left, string $right ): string {
        if ( '' === $left ) { return $right; }
        if ( '' === $right ) { return $left; }
        $lt = strtotime( $left );
        $rt = strtotime( $right );
        if ( false === $lt ) { return $right; }
        if ( false === $rt ) { return $left; }
        return $rt > $lt ? $right : $left;
    }
}
