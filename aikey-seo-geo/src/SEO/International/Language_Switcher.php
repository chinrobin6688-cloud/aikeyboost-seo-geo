<?php
namespace AIKEY\SeoGeo\SEO\International;

defined( 'ABSPATH' ) || exit;

/**
 * Opt-in language picker. No automatic navigation injection, map API, jQuery,
 * or cross-site network request; its small JS only loads when the shortcode runs.
 */
final class Language_Switcher {
    public static function register_hooks(): void {
        add_shortcode( 'aikey_language_switcher', array( self::class, 'shortcode' ) );
        add_action( 'rest_api_init', array( self::class, 'rest_routes' ) );
    }

    public static function rest_routes(): void {
        register_rest_route( 'aikey-seo-geo/v1', '/languages/(?P<post_id>\d+)', array(
            'methods'=>'GET', 'callback'=>array( self::class, 'rest_data' ),
            'permission_callback'=>'__return_true',
            'args'=>array( 'post_id'=>array( 'validate_callback'=>static function ( $value ): bool {
                return is_numeric( $value ) && (int) $value > 0;
            } ) ),
        ) );
    }

    public static function rest_data( \WP_REST_Request $request ): array|\WP_Error {
        $id = absint( $request['post_id'] );
        $post = $id ? get_post( $id ) : null;
        if ( ! $post || '' === Translation_Groups::indexable_url( $post ) ) {
            return new \WP_Error( 'not_found', '公开页面不存在或不可索引。', array( 'status'=>404 ) );
        }
        return Translation_Groups::switcher_data( $id );
    }

    public static function shortcode( array|string $atts = array() ): string {
        $id = (int) get_queried_object_id();
        if ( is_home() && ! is_front_page() ) { $id = (int) get_option( 'page_for_posts', 0 ); }
        if ( $id < 1 ) { return ''; }
        $post = get_post( $id );
        if ( ! $post || '' === Translation_Groups::indexable_url( $post ) ) { return ''; }
        $data = Translation_Groups::switcher_data( $id );
        if ( ! is_array( $data ) || empty( $data['languages'] ) ) { return ''; }
        $choices = array_values( array_filter( $data['languages'], static function ( $entry ): bool {
            return is_array( $entry ) && ! empty( $entry['url'] );
        } ) );
        if ( count( $choices ) < 2 ) { return ''; }

        wp_enqueue_script( 'aikey-language-switcher',
            AIKEY_SEO_GEO_URL . 'assets/js/language-switcher.js',
            array(), AIKEY_SEO_GEO_VERSION, true );
        $id_attr = wp_unique_id( 'aikey-language-' );
        $label = '选择网站语言 / Select website language';
        $html = '<div class="aikey-language-switcher">'
            . '<label class="screen-reader-text" for="' . esc_attr( $id_attr ) . '">' . esc_html( $label ) . '</label>'
            . '<select id="' . esc_attr( $id_attr ) . '" data-aikey-language-switcher aria-label="' . esc_attr( $label ) . '" style="max-width:100%;min-height:36px">';
        foreach ( $choices as $choice ) {
            $html .= '<option value="' . esc_url( (string) $choice['url'] ) . '" lang="' .
                esc_attr( (string) $choice['language'] ) . '"' .
                selected( ! empty( $choice['current'] ), true, false ) . '>' .
                esc_html( (string) $choice['name'] ) . '</option>';
        }
        return $html . '</select></div>';
    }
}
