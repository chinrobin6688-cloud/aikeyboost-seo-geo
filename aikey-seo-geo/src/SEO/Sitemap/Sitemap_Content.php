<?php
namespace AIKEY\SeoGeo\SEO\Sitemap;

use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\SEO\Meta\Meta_Repository;
use AIKEY\SeoGeo\SEO\Meta\Term_Meta;
use AIKEY\SeoGeo\SEO\Redirects\Redirect_Module;
use AIKEY\SeoGeo\Support\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Discovers canonical, indexable WordPress URLs without per-URL HTTP probes.
 */
final class Sitemap_Content {
    /** @var array<string,bool>|null */
    private ?array $redirect_sources = null;
    /** @return array<string,string> */
    public static function available_post_types(): array {
        $out = array();
        $objects = get_post_types( array(), 'objects' );
        foreach ( is_array( $objects ) ? $objects : array() as $name => $obj ) {
            $name = sanitize_key( (string) $name );
            if ( ! self::post_type_is_frontend_indexable( $name, $obj ) ) { continue; }
            $label = isset( $obj->labels->singular_name ) ? (string) $obj->labels->singular_name : $name;
            $out[ $name ] = '' !== $label ? $label : $name;
        }
        return $out;
    }

    /** @return string[] */
    public static function post_types(): array {
        $settings = Settings::section_get( 'sitemap', 'post_types', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $types = array();
        foreach ( array_keys( self::available_post_types() ) as $type ) {
            $enabled = array_key_exists( $type, $settings ) ? ! empty( $settings[ $type ] ) : true;
            if ( $enabled ) { $types[] = $type; }
        }
        return $types;
    }

    /** @return array<string,string> */
    public static function available_taxonomies(): array {
        $out = array();
        $objects = get_taxonomies( array(), 'objects' );
        foreach ( is_array( $objects ) ? $objects : array() as $name => $obj ) {
            $name = sanitize_key( (string) $name );
            if ( ! self::taxonomy_is_frontend_indexable( $name, $obj ) ) { continue; }
            $label = isset( $obj->labels->singular_name ) ? (string) $obj->labels->singular_name : $name;
            $out[ $name ] = '' !== $label ? $label : $name;
        }
        return $out;
    }

    /** @return string[] */
    public static function taxonomies(): array {
        $settings = Settings::section_get( 'sitemap', 'taxonomies', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $taxonomies = array();
        foreach ( array_keys( self::available_taxonomies() ) as $taxonomy ) {
            $default = 'category' === $taxonomy;
            $enabled = array_key_exists( $taxonomy, $settings ) ? ! empty( $settings[ $taxonomy ] ) : $default;
            if ( $enabled ) { $taxonomies[] = $taxonomy; }
        }
        return $taxonomies;
    }

    /** @return array{url:string,lastmod:string,kind:string,type:string}|null */
    public function home_entry(): ?array {
        $url = home_url( '/' );
        if ( ! $this->valid_local_url( $url ) || $this->is_redirect_source( $url ) ) { return null; }

        if ( 'page' === (string) get_option( 'show_on_front', 'posts' ) ) {
            $front_id = absint( get_option( 'page_on_front', 0 ) );
            $post = $front_id > 0 ? get_post( $front_id ) : null;
            if ( ! ( $post instanceof \WP_Post ) || 'page' !== (string) $post->post_type || 'publish' !== (string) $post->post_status || '' !== (string) $post->post_password ) { return null; }
            if ( 'noindex' === Meta_Repository::robots_index( $front_id ) ) { return null; }
            $canonical = trim( Meta_Repository::canonical( $front_id ) );
            if ( '' !== $canonical && ! self::same_url( $url, $canonical ) ) { return null; }
            $lastmod = (string) get_post_modified_time( DATE_W3C, true, $front_id );
            if ( '' !== $lastmod && false === strtotime( $lastmod ) ) { $lastmod = ''; }
            return array(
                'url'     => $url,
                'lastmod' => $lastmod,
                'kind'    => 'home',
                'type'    => 'page',
            );
        }

        return array(
            'url'     => $url,
            'lastmod' => $this->posts_home_lastmod(),
            'kind'    => 'home',
            'type'    => 'home',
        );
    }

    /** @return array{entries:array<int,array{url:string,lastmod:string,kind:string,type:string}>,raw_count:int} */
    public function post_chunk( string $type, int $page, int $page_size ): array {
        if ( ! in_array( $type, self::post_types(), true ) ) { return array( 'entries'=>array(), 'raw_count'=>0 ); }
        $query = new \WP_Query( array(
            'post_type'      => $type,
            'post_status'    => 'publish',
            'has_password'   => false,
            'posts_per_page' => max( 1, $page_size ),
            'paged'          => max( 1, $page ),
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'cache_results'  => true,
            'update_post_term_cache' => false,
            'lazy_load_term_meta' => false,
            'meta_query'     => self::indexable_meta_query(),
        ) );
        $ids = is_array( $query->posts ) ? $query->posts : array();
        $entries = array();
        $seen = array();
        $front_id = 'page' === (string) get_option( 'show_on_front', 'posts' ) ? absint( get_option( 'page_on_front', 0 ) ) : 0;
        foreach ( $ids as $post_id ) {
            $post_id = absint( $post_id );
            if ( $front_id > 0 && $post_id === $front_id ) { continue; }
            $entry = $this->post_entry( $post_id, $type, true );
            $key = null === $entry ? '' : self::url_fingerprint( (string) $entry['url'] );
            if ( null === $entry || '' === $key || isset( $seen[ $key ] ) ) { continue; }
            $seen[ $key ] = true;
            $entries[] = $entry;
        }
        return array( 'entries'=>$entries, 'raw_count'=>count( $ids ) );
    }

    /** @return array<int,array{url:string,lastmod:string,kind:string,type:string}> */
    public function post_entries( string $type, int $page, int $page_size ): array {
        $chunk = $this->post_chunk( $type, $page, $page_size );
        return $chunk['entries'];
    }

    /**
     * Independent low-frequency Page sanity probe for health checks. It bypasses
     * post-type eligibility/settings so a regression that accidentally removes
     * `page` from the normal Sitemap can still be detected, while applying the
     * same noindex/canonical/redirect/manual-exclusion rules to actual entries.
     */
    public function indexable_page_exists( int $page_size = 100 ): bool {
        $page_size = min( 500, max( 20, $page_size ) );
        $home = $this->home_entry();
        if ( is_array( $home ) && 'page' === (string) ( $home['type'] ?? '' ) ) { return true; }
        for ( $page = 1; ; $page++ ) {
            $query = new \WP_Query( array(
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'has_password'   => false,
                'posts_per_page' => $page_size,
                'paged'          => $page,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'no_found_rows'  => true,
                'fields'         => 'ids',
                'cache_results'  => true,
                'update_post_term_cache' => false,
                'lazy_load_term_meta' => false,
                'meta_query'     => self::indexable_meta_query(),
            ) );
            $ids = is_array( $query->posts ) ? $query->posts : array();
            foreach ( $ids as $post_id ) {
                if ( null !== $this->post_entry( absint( $post_id ), 'page', false ) ) { return true; }
            }
            if ( count( $ids ) < $page_size ) { break; }
        }
        return false;
    }

    /** @return array{entries:array<int,array{url:string,lastmod:string,kind:string,type:string}>,raw_count:int} */
    public function term_chunk( string $taxonomy, int $page, int $page_size ): array {
        if ( ! in_array( $taxonomy, self::taxonomies(), true ) ) { return array( 'entries'=>array(), 'raw_count'=>0 ); }
        $terms = get_terms( array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => true,
            'number'     => max( 1, $page_size ),
            'offset'     => ( max( 1, $page ) - 1 ) * max( 1, $page_size ),
            'orderby'    => 'term_id',
            'order'      => 'ASC',
        ) );
        if ( is_wp_error( $terms ) || ! is_array( $terms ) ) { return array( 'entries'=>array(), 'raw_count'=>0 ); }
        $entries = array();
        $seen = array();
        foreach ( $terms as $term ) {
            if ( ! is_object( $term ) || empty( $term->term_id ) ) { continue; }
            if ( 'noindex' === (string) get_term_meta( (int) $term->term_id, Term_Meta::ROBOTS_INDEX, true ) ) { continue; }
            $url = get_term_link( $term );
            if ( is_wp_error( $url ) || ! is_string( $url ) || ! $this->valid_local_url( $url ) || $this->is_redirect_source( $url ) || self::url_is_manually_excluded( $url ) ) { continue; }
            $key = self::url_fingerprint( $url );
            if ( '' === $key || isset( $seen[ $key ] ) ) { continue; }
            $seen[ $key ] = true;
            $entries[] = array( 'url'=>$url, 'lastmod'=>'', 'kind'=>'taxonomy', 'type'=>$taxonomy );
        }
        return array( 'entries'=>$entries, 'raw_count'=>count( $terms ) );
    }

    /** @return array<int,array{url:string,lastmod:string,kind:string,type:string}> */
    public function term_entries( string $taxonomy, int $page, int $page_size ): array {
        $chunk = $this->term_chunk( $taxonomy, $page, $page_size );
        return $chunk['entries'];
    }

    /**
     * Collect enough unique entries to decide whether the main Sitemap can be a
     * direct urlset. It intentionally stops as soon as limit+1 is reached.
     *
     * @return array{entries:array<int,array<string,mixed>>,overflow:bool,stats:array<string,int>,lastmod:string}
     */
    public function collect_until( int $limit, int $scan_page_size ): array {
        $entries = array();
        $seen = array();
        $stats = self::empty_stats();
        $latest = '';
        $push = function ( array $entry ) use ( &$entries, &$seen, &$stats, &$latest, $limit ): bool {
            $url = (string) ( $entry['url'] ?? '' );
            $key = self::url_fingerprint( $url );
            if ( '' === $url || '' === $key || isset( $seen[ $key ] ) ) { return false; }
            $seen[ $key ] = true;
            $entries[] = $entry;
            self::count_entry( $stats, $entry );
            $latest = self::newest( $latest, (string) ( $entry['lastmod'] ?? '' ) );
            return count( $entries ) > $limit;
        };

        $home = $this->home_entry();
        if ( null !== $home && $push( $home ) ) { return array( 'entries'=>$entries, 'overflow'=>true, 'stats'=>$stats, 'lastmod'=>$latest ); }

        foreach ( self::post_types() as $type ) {
            for ( $page = 1; ; $page++ ) {
                $chunk = $this->post_chunk( $type, $page, $scan_page_size );
                $raw_count = (int) $chunk['raw_count'];
                if ( 0 === $raw_count ) { break; }
                foreach ( $chunk['entries'] as $entry ) {
                    if ( $push( $entry ) ) { return array( 'entries'=>$entries, 'overflow'=>true, 'stats'=>$stats, 'lastmod'=>$latest ); }
                }
                if ( $raw_count < $scan_page_size ) { break; }
            }
        }

        foreach ( self::taxonomies() as $taxonomy ) {
            for ( $page = 1; ; $page++ ) {
                $chunk = $this->term_chunk( $taxonomy, $page, $scan_page_size );
                $raw_count = (int) $chunk['raw_count'];
                if ( 0 === $raw_count ) { break; }
                foreach ( $chunk['entries'] as $entry ) {
                    if ( $push( $entry ) ) { return array( 'entries'=>$entries, 'overflow'=>true, 'stats'=>$stats, 'lastmod'=>$latest ); }
                }
                if ( $raw_count < $scan_page_size ) { break; }
            }
        }

        return array( 'entries'=>$entries, 'overflow'=>false, 'stats'=>$stats, 'lastmod'=>$latest );
    }

    /**
     * Build non-empty child Sitemap descriptors and exact counts for index mode.
     * A global canonical-URL set is applied while constructing the index so one
     * URL is owned by only one child file.
     *
     * @return array{children:array<int,array<string,mixed>>,stats:array<string,int>,lastmod:string}
     */
    public function build_index( int $page_size ): array {
        $children = array();
        $stats = self::empty_stats();
        $latest = '';
        $seen = array();
        $duplicate_owners = array();
        $unique = static function ( array $entries, string $owner ) use ( &$seen, &$duplicate_owners ): array {
            $out = array();
            foreach ( $entries as $entry ) {
                $url = (string) ( $entry['url'] ?? '' );
                $fingerprint = self::url_fingerprint( $url );
                if ( '' === $url || '' === $fingerprint ) { continue; }
                if ( isset( $seen[ $fingerprint ] ) ) {
                    if ( $seen[ $fingerprint ] !== $owner && count( $duplicate_owners ) < 5000 ) {
                        $duplicate_owners[ $fingerprint ] = $seen[ $fingerprint ];
                    }
                    continue;
                }
                $seen[ $fingerprint ] = $owner;
                $out[] = $entry;
            }
            return $out;
        };

        $home = $this->home_entry();
        if ( null !== $home ) {
            $home_entries = $unique( array( $home ), 'home--1' );
            if ( $home_entries ) {
                $children[] = array( 'url'=>home_url( '/aikey-sitemap-home.xml' ), 'lastmod'=>(string) $home['lastmod'], 'cache_key'=>'home--1' );
                self::count_entry( $stats, $home );
                $latest = self::newest( $latest, (string) $home['lastmod'] );
            }
        }

        foreach ( self::post_types() as $type ) {
            for ( $page = 1; ; $page++ ) {
                $chunk = $this->post_chunk( $type, $page, $page_size );
                $raw_count = (int) $chunk['raw_count'];
                if ( 0 === $raw_count ) { break; }
                $owner = 'post-' . $type . '-' . $page;
                $entries = $unique( $chunk['entries'], $owner );
                if ( $entries ) {
                    $chunk_latest = '';
                    foreach ( $entries as $entry ) {
                        self::count_entry( $stats, $entry );
                        $chunk_latest = self::newest( $chunk_latest, (string) $entry['lastmod'] );
                        $latest = self::newest( $latest, (string) $entry['lastmod'] );
                    }
                    $children[] = array(
                        'url'       => home_url( '/aikey-sitemap-post-' . rawurlencode( $type ) . '-' . $page . '.xml' ),
                        'lastmod'   => $chunk_latest,
                        'cache_key' => 'post-' . $type . '-' . $page,
                    );
                }
                if ( $raw_count < $page_size ) { break; }
            }
        }

        foreach ( self::taxonomies() as $taxonomy ) {
            for ( $page = 1; ; $page++ ) {
                $chunk = $this->term_chunk( $taxonomy, $page, $page_size );
                $raw_count = (int) $chunk['raw_count'];
                if ( 0 === $raw_count ) { break; }
                $owner = 'tax-' . $taxonomy . '-' . $page;
                $entries = $unique( $chunk['entries'], $owner );
                if ( $entries ) {
                    foreach ( $entries as $entry ) { self::count_entry( $stats, $entry ); }
                    $children[] = array(
                        'url'       => home_url( '/aikey-sitemap-tax-' . rawurlencode( $taxonomy ) . '-' . $page . '.xml' ),
                        'lastmod'   => '',
                        'cache_key' => 'tax-' . $taxonomy . '-' . $page,
                    );
                }
                if ( $raw_count < $page_size ) { break; }
            }
        }
        Sitemap_Cache::set_duplicate_owners( $duplicate_owners );
        return array( 'children'=>$children, 'stats'=>$stats, 'lastmod'=>$latest );
    }

    /** @return array<int,array<string,mixed>> */
    public function direct_entries( int $limit, int $page_size ): array {
        $result = $this->collect_until( max( 1, $limit ), $page_size );
        return array_slice( $result['entries'], 0, max( 1, $limit ) );
    }

    /** @return array<string,int> */
    public static function empty_stats(): array {
        return array( 'total'=>0, 'home'=>0, 'page'=>0, 'post'=>0, 'cpt'=>0, 'taxonomy'=>0 );
    }

    /** @param array<string,int> $stats @param array<string,mixed> $entry */
    private static function count_entry( array &$stats, array $entry ): void {
        $stats['total']++;
        $kind = (string) ( $entry['kind'] ?? '' );
        $type = (string) ( $entry['type'] ?? '' );
        if ( 'home' === $kind ) {
            $stats['home']++;
            if ( 'page' === (string) get_option( 'show_on_front', 'posts' ) ) { $stats['page']++; }
            return;
        }
        if ( 'taxonomy' === $kind ) { $stats['taxonomy']++; return; }
        if ( 'page' === $type ) { $stats['page']++; }
        elseif ( 'post' === $type ) { $stats['post']++; }
        else { $stats['cpt']++; }
    }

    /**
     * Single source of truth for pages advertised to crawlers and search engines.
     * Reuse the Sitemap's existing noindex, password, canonical, manual-exclusion
     * and redirect tests; preserve the static homepage's dedicated policy.
     */
    public function indexable_post_url( \WP_Post $post ): string {
        if ( 'page' === (string) get_option( 'show_on_front', 'posts' )
            && (int) get_option( 'page_on_front', 0 ) === (int) $post->ID ) {
            $home = $this->home_entry();
            return is_array( $home ) ? (string) $home['url'] : '';
        }
        $entry = $this->post_entry( (int) $post->ID, (string) $post->post_type, true );
        return is_array( $entry ) ? (string) $entry['url'] : '';
    }

    /** @return array{url:string,lastmod:string,kind:string,type:string}|null */
    private function post_entry( int $post_id, string $expected_type, bool $require_type_enabled ): ?array {
        $post = get_post( $post_id );
        if ( ! ( $post instanceof \WP_Post ) || 'publish' !== (string) $post->post_status || '' !== (string) $post->post_password ) { return null; }
        if ( $expected_type !== (string) $post->post_type ) { return null; }
        if ( $require_type_enabled && ! in_array( $expected_type, self::post_types(), true ) ) { return null; }
        if ( self::post_id_is_manually_excluded( $post_id ) ) { return null; }
        if ( 'noindex' === Meta_Repository::robots_index( $post_id ) ) { return null; }

        $url = get_permalink( $post_id );
        if ( ! is_string( $url ) || ! $this->valid_local_url( $url ) || self::url_is_manually_excluded( $url ) ) { return null; }
        $canonical = trim( Meta_Repository::canonical( $post_id ) );
        if ( '' !== $canonical && ! self::same_url( $url, $canonical ) ) { return null; }
        if ( $this->is_redirect_source( $url ) ) { return null; }

        $lastmod = (string) get_post_modified_time( DATE_W3C, true, $post_id );
        if ( '' !== $lastmod && false === strtotime( $lastmod ) ) { $lastmod = ''; }
        return array(
            'url'     => $url,
            'lastmod' => $lastmod,
            'kind'    => 'post',
            'type'    => $expected_type,
        );
    }

    private function posts_home_lastmod(): string {
        if ( ! function_exists( 'get_lastpostmodified' ) ) { return ''; }
        $last = trim( (string) get_lastpostmodified( 'GMT' ) );
        if ( '' === $last ) { return ''; }
        $time = strtotime( $last . ' UTC' );
        return $time ? gmdate( DATE_W3C, $time ) : '';
    }

    private function is_redirect_source( string $url ): bool {
        if ( null === $this->redirect_sources ) {
            $this->redirect_sources = array();
            try {
                foreach ( Redirect_Module::enabled_source_paths() as $path ) { $this->redirect_sources[ Utils::normalize_path( $path ) ] = true; }
            } catch ( \Throwable $error ) {
                // Redirect filtering is best-effort if a partially upgraded DB is unavailable.
                $this->redirect_sources = array();
            }
        }
        return isset( $this->redirect_sources[ Utils::normalize_path( $url ) ] );
    }

    private function valid_local_url( string $url ): bool {
        $url = esc_url_raw( $url, array( 'http', 'https' ) );
        if ( '' === $url ) { return false; }
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        return '' !== $host && '' !== $home_host && $host === $home_host;
    }

    private static function same_url( string $left, string $right ): bool {
        $normalize = static function ( string $url ): string {
            $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
            $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
            $port = (string) wp_parse_url( $url, PHP_URL_PORT );
            $path = (string) wp_parse_url( $url, PHP_URL_PATH );
            $query = (string) wp_parse_url( $url, PHP_URL_QUERY );
            $path = '/' === $path ? '/' : untrailingslashit( '/' . ltrim( rawurldecode( $path ), '/' ) );
            return $scheme . '://' . $host . ( '' !== $port ? ':' . $port : '' ) . $path . ( '' !== $query ? '?' . $query : '' );
        };
        return $normalize( $left ) === $normalize( $right );
    }

    private static function post_type_is_frontend_indexable( string $name, mixed $obj ): bool {
        if ( '' === $name || 'attachment' === $name || ! is_object( $obj ) ) { return false; }

        // WordPress core Page is the important exception that originally exposed
        // the regression: built-in post/page must not be rejected merely because
        // publicly_queryable is false/narrowed in the type object.
        if ( in_array( $name, array( 'post', 'page' ), true ) ) { return true; }

        if ( empty( $obj->public ) || ! empty( $obj->exclude_from_search ) ) { return false; }

        // For third-party CPTs, an explicit publicly_queryable=false is meaningful:
        // WordPress does not promise a public single-item query route. Including it
        // only because rewrite/show_ui exists can put real 404 URLs into Sitemap.
        if ( isset( $obj->publicly_queryable ) && false === $obj->publicly_queryable ) { return false; }

        $queryable = ! empty( $obj->publicly_queryable );
        $has_rewrite = isset( $obj->rewrite ) && false !== $obj->rewrite;
        $has_query = ! empty( $obj->query_var );
        $has_archive = ! empty( $obj->has_archive );

        // A normal public CPT registered through register_post_type() inherits
        // publicly_queryable from `public` unless explicitly overridden. Require
        // queryability plus at least one concrete front-end addressing mechanism.
        return $queryable && ( $has_rewrite || $has_query || $has_archive );
    }

    private static function taxonomy_is_frontend_indexable( string $name, mixed $obj ): bool {
        if ( '' === $name || 'post_format' === $name || ! is_object( $obj ) || empty( $obj->public ) ) { return false; }
        if ( isset( $obj->publicly_queryable ) && false === $obj->publicly_queryable ) { return false; }
        if ( isset( $obj->rewrite ) && false === $obj->rewrite ) { return false; }
        return true;
    }

    private static function post_id_is_manually_excluded( int $post_id ): bool {
        $ids = Settings::section_get( 'sitemap', 'exclude_post_ids', array() );
        if ( ! is_array( $ids ) ) { return false; }
        foreach ( $ids as $id ) {
            if ( $post_id === absint( $id ) ) { return true; }
        }
        return false;
    }

    private static function url_is_manually_excluded( string $url ): bool {
        $urls = Settings::section_get( 'sitemap', 'exclude_urls', array() );
        if ( ! is_array( $urls ) ) { return false; }
        $key = self::url_key( $url );
        if ( '' === $key ) { return false; }
        foreach ( $urls as $excluded ) {
            if ( $key === self::url_key( (string) $excluded ) ) { return true; }
        }
        return false;
    }

    public static function url_fingerprint( string $url ): string {
        $key = self::url_key( $url );
        return '' === $key ? '' : hash( 'sha256', $key );
    }

    private static function url_key( string $url ): string {
        $url = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
        if ( '' === $url ) { return ''; }
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $port = (string) wp_parse_url( $url, PHP_URL_PORT );
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $query = (string) wp_parse_url( $url, PHP_URL_QUERY );
        $path = '/' === $path || '' === $path ? '/' : untrailingslashit( '/' . ltrim( rawurldecode( $path ), '/' ) );
        return $scheme . '://' . $host . ( '' !== $port ? ':' . $port : '' ) . $path . ( '' !== $query ? '?' . $query : '' );
    }

    private static function newest( string $left, string $right ): string {
        if ( '' === $left ) { return $right; }
        if ( '' === $right ) { return $left; }
        $left_time = strtotime( $left );
        $right_time = strtotime( $right );
        if ( false === $left_time ) { return $right; }
        if ( false === $right_time ) { return $left; }
        return $right_time > $left_time ? $right : $left;
    }

    private static function indexable_meta_query(): array {
        return array(
            'relation' => 'OR',
            array( 'key'=>Meta_Repository::ROBOTS_INDEX, 'compare'=>'NOT EXISTS' ),
            array( 'key'=>Meta_Repository::ROBOTS_INDEX, 'value'=>'noindex', 'compare'=>'!=' ),
        );
    }
}
