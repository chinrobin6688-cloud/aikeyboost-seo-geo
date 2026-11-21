<?php
namespace AIKEY\SeoGeo\SEO\InternalLinks;

use AIKEY\SeoGeo\Core\Module_Interface;
use AIKEY\SeoGeo\SEO\Meta\Meta_Repository;
use AIKEY\SeoGeo\Support\Utils;

defined( 'ABSPATH' ) || exit;

final class Internal_Links_Module implements Module_Interface {
    public function id(): string { return 'internal_links'; }

    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'routes' ) );
    }

    public function routes(): void {
        register_rest_route( 'aikey-seo-geo/v1', '/links/suggest/(?P<id>\d+)', array(
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'suggest' ),
            'permission_callback' => static fn( \WP_REST_Request $request ): bool => current_user_can( 'edit_post', (int) $request['id'] ),
            'args'                => array( 'id' => array( 'validate_callback' => static fn( $v ): bool => is_numeric( $v ) && (int) $v > 0 ) ),
        ) );
    }

    public function suggest( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $post_id = (int) $request['id'];
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) { return new \WP_Error( 'not_found', '页面不存在。', array( 'status'=>404 ) ); }

        $payload = $request->get_json_params();
        $title = sanitize_text_field( (string) ( $payload['title'] ?? get_the_title( $post ) ) );
        $keyword = sanitize_text_field( (string) ( $payload['keyword'] ?? Meta_Repository::focus_keyword( $post_id ) ) );
        $content = wp_strip_all_tags( (string) ( $payload['content'] ?? $post->post_content ) );
        if ( function_exists( 'mb_substr' ) ) { $content = mb_substr( $content, 0, 4000, 'UTF-8' ); } else { $content = substr( $content, 0, 4000 ); }
        $needles = self::tokens( $keyword . ' ' . $title . ' ' . $content );

        $candidates = get_posts( array(
            'post_type'      => Utils::public_post_types(),
            'post_status'    => 'publish',
            'posts_per_page' => 120,
            'post__not_in'   => array( $post_id ),
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ) );
        $rows = array();
        foreach ( $candidates as $candidate ) {
            $candidate_title = (string) get_the_title( $candidate );
            $candidate_keyword = Meta_Repository::focus_keyword( $candidate->ID );
            $candidate_text = $candidate_title . ' ' . $candidate_keyword;
            $score = 0;
            foreach ( $needles as $token => $weight ) {
                if ( self::contains( $candidate_text, $token ) ) { $score += $weight; }
            }
            if ( '' !== $keyword && self::contains( $candidate_text, $keyword ) ) { $score += 20; }
            if ( $score < 4 ) { continue; }
            $url = get_permalink( $candidate );
            if ( ! $url ) { continue; }
            $rows[] = array(
                'id'    => (int) $candidate->ID,
                'title' => $candidate_title,
                'url'   => esc_url_raw( $url ),
                'score' => $score,
            );
        }
        usort( $rows, static fn( array $a, array $b ): int => $b['score'] <=> $a['score'] );
        return rest_ensure_response( array( 'ok'=>true, 'suggestions'=>array_slice( $rows, 0, 8 ) ) );
    }

    private static function tokens( string $text ): array {
        $text = trim( wp_strip_all_tags( $text ) );
        $weights = array();
        if ( '' === $text ) { return $weights; }

        preg_match_all( '/[A-Za-z0-9][A-Za-z0-9\-_]{2,}/u', strtolower( $text ), $latin );
        foreach ( array_slice( array_unique( $latin[0] ?? array() ), 0, 40 ) as $word ) { $weights[ $word ] = 5; }

        preg_match_all( '/[\p{Han}]{2,10}/u', $text, $chunks );
        foreach ( array_slice( $chunks[0] ?? array(), 0, 60 ) as $chunk ) {
            $len = function_exists( 'mb_strlen' ) ? mb_strlen( $chunk, 'UTF-8' ) : strlen( $chunk );
            if ( $len <= 4 ) { $weights[ $chunk ] = max( $weights[ $chunk ] ?? 0, 7 ); continue; }
            $max = min( $len - 1, 12 );
            for ( $i = 0; $i < $max; $i++ ) {
                $gram = function_exists( 'mb_substr' ) ? mb_substr( $chunk, $i, 3, 'UTF-8' ) : substr( $chunk, $i, 3 );
                if ( '' !== $gram ) { $weights[ $gram ] = max( $weights[ $gram ] ?? 0, 3 ); }
            }
        }
        arsort( $weights );
        return array_slice( $weights, 0, 80, true );
    }

    private static function contains( string $haystack, string $needle ): bool {
        if ( '' === $needle ) { return false; }
        return function_exists( 'mb_stripos' ) ? false !== mb_stripos( $haystack, $needle, 0, 'UTF-8' ) : false !== stripos( $haystack, $needle );
    }
}
