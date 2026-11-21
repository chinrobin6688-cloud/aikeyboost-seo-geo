<?php
namespace AIKEY\SeoGeo\SEO\Meta;

use AIKEY\SeoGeo\Core\Conflict_Detector;
use AIKEY\SeoGeo\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Frontend {
    public function register(): void {
        add_filter( 'pre_get_document_title', array( $this, 'document_title' ), 20 );
        add_filter( 'get_canonical_url', array( $this, 'canonical' ), 20, 2 );
        add_filter( 'wp_robots', array( $this, 'robots' ), 20 );
        add_action( 'wp_head', array( $this, 'head_meta' ), 1 );
        add_action( 'wp_head', array( $this, 'homepage_hreflang' ), 2 );
    }

    public function document_title( string $title ): string {
        if ( ! $this->should_output() ) { return $title; }
        if ( is_front_page() ) {
            return Meta_Repository::resolve_seo_title( Meta_Repository::front_page_id(), true, $title );
        }
        $term = $this->current_term();
        if ( $term ) {
            $custom = (string) get_term_meta( $term->term_id, Term_Meta::TITLE, true );
            return '' !== trim( $custom ) ? $custom : $title;
        }
        $post = $this->current_post();
        if ( ! $post ) { return $title; }
        return Meta_Repository::resolve_seo_title( (int) $post->ID, false, $title );
    }

    public function canonical( string $canonical_url, \WP_Post $post ): string {
        if ( ! $this->should_output() ) { return $canonical_url; }
        $custom = Meta_Repository::canonical( (int) $post->ID );
        return '' !== $custom ? $custom : $canonical_url;
    }

    public function robots( array $robots ): array {
        if ( ! $this->should_output() ) { return $robots; }
        $term = $this->current_term();
        if ( $term ) {
            $index = (string) get_term_meta( $term->term_id, Term_Meta::ROBOTS_INDEX, true );
            if ( 'noindex' === $index ) { unset( $robots['index'] ); $robots['noindex'] = true; }
            elseif ( 'index' === $index && (bool) get_option( 'blog_public', true ) ) { unset( $robots['noindex'] ); $robots['index'] = true; }
            return $robots;
        }
        $post = $this->current_post();
        if ( ! $post ) { return $robots; }
        $index = Meta_Repository::robots_index( (int) $post->ID );
        $follow = Meta_Repository::robots_follow( (int) $post->ID );
        if ( 'noindex' === $index ) { unset( $robots['index'] ); $robots['noindex'] = true; }
        elseif ( 'index' === $index && (bool) get_option( 'blog_public', true ) ) { unset( $robots['noindex'] ); $robots['index'] = true; }
        if ( 'nofollow' === $follow ) { unset( $robots['follow'] ); $robots['nofollow'] = true; }
        elseif ( 'follow' === $follow ) { unset( $robots['nofollow'] ); $robots['follow'] = true; }
        return $robots;
    }

    public function head_meta(): void {
        if ( ! $this->should_output() ) { return; }
        $description = '';
        $title = wp_get_document_title();
        $url = home_url( '/' );
        $image = '';
        if ( is_front_page() ) {
            $description = Meta_Repository::resolve_seo_description( Meta_Repository::front_page_id(), true );
            $front_post = get_post( Meta_Repository::front_page_id() );
            if ( $front_post instanceof \WP_Post ) { $url = Meta_Repository::resolved_canonical_url( $front_post ); }
            // WordPress core already prints rel_canonical() for a static front page because it
            // is singular. Only print our own canonical for the non-singular posts homepage.
            if ( ! is_singular() ) {
                echo '<link rel="canonical" href="' . esc_url( home_url( '/' ) ) . '">' . "\n";
            }
        } elseif ( $term = $this->current_term() ) {
            $description = trim( (string) get_term_meta( $term->term_id, Term_Meta::DESCRIPTION, true ) );
            if ( '' === $description ) { $description = trim( wp_strip_all_tags( term_description( $term ) ) ); }
            $term_url = get_term_link( $term );
            if ( ! is_wp_error( $term_url ) ) { $url = (string) $term_url; echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n"; }
        } else {
            $post = $this->current_post();
            if ( ! $post || post_password_required( $post ) ) { return; }
            $description = Meta_Repository::effective_description( $post );
            $url = Meta_Repository::resolved_canonical_url( $post );
            $image = (string) get_the_post_thumbnail_url( $post, 'large' );
        }
        $description = Meta_Repository::truncate( $description, 180 );
        if ( '' !== $description ) { echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n"; }
        if ( ! Settings::seo_meta( 'open_graph', true ) ) { return; }
        echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
        if ( '' !== $description ) { echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n"; }
        echo '<meta property="og:type" content="' . esc_attr( is_singular( 'post' ) ? 'article' : 'website' ) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
        if ( '' !== $image ) { echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n"; }
        echo '<meta name="twitter:card" content="' . esc_attr( '' !== $image ? 'summary_large_image' : 'summary' ) . '">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
        if ( '' !== $description ) { echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '">' . "\n"; }
    }

    /**
     * Export only an actual bilingual homepage pair. English inner pages require
     * an explicit mapping and are intentionally not guessed from URL slugs.
     */
    public function homepage_hreflang(): void {
        if ( ! $this->should_output() ) { return; }
        // These fallback links are only for the homepage or the /en/ page.
        // Avoid querying the English page on every unrelated post/product view.
        if ( ! is_singular() ) { return; }
        $is_home = is_front_page();
        $queried_id = (int) get_queried_object_id();
        $is_en_candidate = is_page( 'en' );
        if ( ! $is_home && ! $is_en_candidate ) { return; }

        // The explicit international module owns hreflang when enabled.
        $intl = Settings::section( 'international' );
        if ( ! empty( $intl['enabled'] ) || ! empty( $intl['id_links_enabled'] ) ) { return; }
        $en = get_page_by_path( 'en', OBJECT, 'page' );
        if ( ! $en instanceof \WP_Post || 'publish' !== $en->post_status || '' !== $en->post_password ) { return; }
        if ( 'noindex' === Meta_Repository::robots_index( (int) $en->ID ) ) { return; }
        $en_url = (string) get_permalink( $en );
        if ( untrailingslashit( $en_url ) !== untrailingslashit( home_url( '/en/' ) ) ) { return; }
        $home_id = Meta_Repository::front_page_id();
        if ( $home_id < 1 || 'noindex' === Meta_Repository::robots_index( $home_id ) ) { return; }
        $home = get_post( $home_id );
        if ( ! $home instanceof \WP_Post || 'publish' !== $home->post_status || '' !== $home->post_password ) { return; }
        if ( untrailingslashit( Meta_Repository::resolved_canonical_url( $en ) ) !== untrailingslashit( $en_url ) ) { return; }
        if ( untrailingslashit( Meta_Repository::resolved_canonical_url( $home ) ) !== untrailingslashit( home_url( '/' ) ) ) { return; }

        $is_zh = $is_home && $queried_id === $home_id;
        $is_en = $is_en_candidate && $queried_id === (int) $en->ID;
        if ( ! $is_zh && ! $is_en ) { return; }
        echo '<link rel="alternate" hreflang="zh-CN" href="' . esc_url( home_url( '/' ) ) . '">' . "\n";
        echo '<link rel="alternate" hreflang="en" href="' . esc_url( $en_url ) . '">' . "\n";
    }

    private function current_post(): ?\WP_Post {
        if ( ! $this->should_output() || ! is_singular() ) { return null; }
        $post = get_queried_object();
        return $post instanceof \WP_Post ? $post : null;
    }
    private function current_term(): ?\WP_Term {
        if ( ! $this->should_output() || ! ( is_category() || is_tag() || is_tax() ) ) { return null; }
        $term = get_queried_object();
        return $term instanceof \WP_Term ? $term : null;
    }
    private function should_output(): bool {
        return ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() && Conflict_Detector::frontend_allowed();
    }
}
