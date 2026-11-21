<?php
namespace AIKEY\SeoGeo\GEO;

use AIKEY\SeoGeo\Core\Conflict_Detector;
use AIKEY\SeoGeo\Core\Module_Interface;
use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\SEO\Meta\Meta_Repository;
use AIKEY\SeoGeo\SEO\Sitemap\Sitemap_Content;
use AIKEY\SeoGeo\Support\Utils;

defined( 'ABSPATH' ) || exit;

final class Llms_Module implements Module_Interface {
    private const MAX_FULL_BYTES = 400000;
    private const MAX_FULL_POSTS = 200;

    public function id(): string { return 'llms'; }

    public function register(): void {
        add_action( 'init', array( $this, 'rewrite' ) );
        add_filter( 'query_vars', array( $this, 'query_vars' ) );
        add_action( 'template_redirect', array( $this, 'render' ), -5 );
    }

    public function rewrite(): void {
        if ( ! Conflict_Detector::frontend_allowed() ) { return; }
        add_rewrite_rule( '^llms\.txt$', 'index.php?aikey_llms=1', 'top' );
        add_rewrite_rule( '^llms-full\.txt$', 'index.php?aikey_llms_full=1', 'top' );
    }

    public function query_vars( array $vars ): array {
        $vars[] = 'aikey_llms';
        $vars[] = 'aikey_llms_full';
        return $vars;
    }

    public function render(): void {
        $full = '1' === (string) get_query_var( 'aikey_llms_full' );
        if ( ! $full && '1' !== (string) get_query_var( 'aikey_llms' ) ) { return; }
        if ( ! Conflict_Detector::frontend_allowed() || ! (bool) get_option( 'blog_public', true ) ) {
            status_header( 404 );
            exit;
        }

        nocache_headers();
        header( 'Content-Type: text/plain; charset=UTF-8' );
        $heading = $this->heading( $full );
        echo $heading; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text response, not HTML.

        $limit = $full ? self::MAX_FULL_POSTS
            : min( 200, max( 10, (int) Settings::section_get( 'llms', 'max_links', 50 ) ) );
        $posts = get_posts( array(
            'post_type' => Utils::public_post_types(),
            'post_status' => 'publish',
            'has_password' => false,
            'posts_per_page' => $limit,
            'orderby' => 'modified',
            'order' => 'DESC',
        ) );
        $indexable = new Sitemap_Content();
        $remaining = $full ? max( 0, self::MAX_FULL_BYTES - strlen( $heading ) ) : 0;

        foreach ( $posts as $post ) {
            if ( ! $post instanceof \WP_Post || '' !== (string) $post->post_password ) { continue; }
            $url = $indexable->indexable_post_url( $post );
            if ( '' === $url ) { continue; }
            $description = Meta_Repository::effective_description( $post );

            if ( ! $full ) {
                echo '- [' . sanitize_text_field( get_the_title( $post ) ) . '](' . esc_url_raw( $url ) . ')'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/plain Markdown.
                if ( '' !== $description ) {
                    echo ': ' . str_replace( array( "\r", "\n" ), ' ', $description ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text description.
                }
                echo "\n";
                continue;
            }

            if ( $remaining <= 0 ) { break; }
            $title = sanitize_text_field( get_the_title( $post ) );
            $entry = "\n### [" . $title . '](' . esc_url_raw( $url ) . ")\n\n";
            if ( '' !== $description ) {
                $entry .= sanitize_text_field( $description ) . "\n\n";
            }
            // Read raw stored blocks; never call the_content or execute shortcodes.
            $plain = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ), true );
            $plain = html_entity_decode( $plain, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $plain = trim( (string) preg_replace( '/\s+/u', ' ', $plain ) );
            if ( '' !== $plain ) { $entry .= $plain . "\n"; }
            $entry = self::byte_limit( $entry, $remaining );
            echo $entry; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bounded, plain-text response.
            $remaining -= strlen( $entry );
        }
        exit;
    }

    private function heading( bool $full ): string {
        $schema = Settings::section( 'schema' );
        $name = trim( (string) ( $schema['organization_name'] ?? '' ) );
        $name = '' !== $name ? $name : get_bloginfo( 'name' );
        $heading = '# ' . $name . "\n\n";
        $heading .= '> ' . trim( get_bloginfo( 'description' ) ) . "\n\n";
        $heading .= 'Website: ' . home_url( '/' ) . "\n";
        if ( ! empty( $schema['telephone'] ) ) {
            $heading .= 'Telephone: ' . sanitize_text_field( (string) $schema['telephone'] ) . "\n";
        }
        if ( ! empty( $schema['email'] ) ) {
            $heading .= 'Email: ' . sanitize_email( (string) $schema['email'] ) . "\n";
        }
        if ( ! empty( $schema['address'] ) ) {
            $heading .= 'Address: ' . sanitize_text_field( (string) $schema['address'] ) . "\n";
        }
        return $heading . ( $full ? "\n## Published pages\n" : "\n## Key pages\n" );
    }

    /** Hard output bound that does not split a UTF-8 code point without mbstring. */
    private static function byte_limit( string $text, int $max ): string {
        if ( strlen( $text ) <= $max ) { return $text; }
        if ( function_exists( 'mb_strcut' ) ) { return mb_strcut( $text, 0, $max, 'UTF-8' ); }
        $part = substr( $text, 0, $max );
        while ( '' !== $part && 1 !== preg_match( '//u', $part ) ) {
            $part = substr( $part, 0, -1 );
        }
        return $part;
    }
}
