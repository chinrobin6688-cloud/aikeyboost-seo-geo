<?php
namespace AIKEY\SeoGeo\Automation;

use AIKEY\SeoGeo\Core\Module_Interface;
use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\GEO\Scorer;
use AIKEY\SeoGeo\SEO\Audit\Audit_Module;
use AIKEY\SeoGeo\SEO\Meta\Meta_Repository;
use AIKEY\SeoGeo\Support\Utils;

defined( 'ABSPATH' ) || exit;

final class Automation_Module implements Module_Interface {
    private const CRON = 'aikey_seo_geo_daily_maintenance';
    private const OFFSET_OPTION = 'aikey_seo_geo_maintenance_offset';

    public function id(): string { return 'automation'; }

    public function register(): void {
        add_action( 'init', array( $this, 'ensure_schedule' ) );
        add_action( 'init', array( $this, 'register_rest_hooks' ), 100 );
        add_action( self::CRON, array( $this, 'daily_maintenance' ) );
        add_action( 'save_post', array( $this, 'on_save' ), 99, 3 );
    }

    public function ensure_schedule(): void {
        if ( ! Settings::section_get( 'automation', 'daily_maintenance', true ) ) {
            wp_clear_scheduled_hook( self::CRON );
            return;
        }
        if ( ! wp_next_scheduled( self::CRON ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
        }
    }

    /**
     * Gutenberg saves registered REST meta after wp_insert_post()/save_post.
     * Run a second, authoritative automation pass after REST meta has landed so
     * Schema/scoring always use the user's newest editor values.
     */
    public function register_rest_hooks(): void {
        foreach ( Utils::public_post_types() as $post_type ) {
            add_action( 'rest_after_insert_' . $post_type, array( $this, 'on_rest_after_insert' ), 99, 3 );
        }
    }

    public function on_save( int $post_id, \WP_Post $post, bool $update ): void {
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) { return; }
        $this->process_post( $post_id, $post );
    }

    public function on_rest_after_insert( \WP_Post $post, \WP_REST_Request $request, bool $creating ): void {
        $this->process_post( (int) $post->ID, $post );
    }

    private function process_post( int $post_id, \WP_Post $post ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'publish' !== $post->post_status ) { return; }
        if ( ! in_array( $post->post_type, Utils::public_post_types(), true ) ) { return; }
        $do_optimize = (bool) Settings::section_get( 'automation', 'optimize_on_save', true );
        $do_score = (bool) Settings::section_get( 'automation', 'auto_score', true );
        if ( ! $do_optimize && ! $do_score ) { return; }

        // Re-read after REST additional fields/meta have been written.
        $fresh = get_post( $post_id );
        if ( $fresh instanceof \WP_Post ) { $post = $fresh; }
        if ( $do_optimize ) { $this->apply_safe_defaults( $post ); }

        if ( $do_score ) {
            Audit_Module::persist( $post );
            Scorer::persist( $post );
        }
        update_post_meta( $post_id, '_aikey_last_automated_at', gmdate( 'c' ) );
    }

    public function daily_maintenance(): void {
        if ( ! Settings::section_get( 'automation', 'daily_maintenance', true ) ) { return; }
        $limit = min( 500, max( 20, (int) Settings::section_get( 'automation', 'daily_scan_limit', 200 ) ) );
        $offset = max( 0, (int) get_option( self::OFFSET_OPTION, 0 ) );
        $posts = self::maintenance_batch( $limit, $offset );

        if ( empty( $posts ) && $offset > 0 ) {
            $offset = 0;
            $posts = self::maintenance_batch( $limit, 0 );
        }

        foreach ( $posts as $post ) {
            $this->apply_safe_defaults( $post );
            Audit_Module::persist( $post );
            Scorer::persist( $post );
        }

        $next = count( $posts ) < $limit ? 0 : $offset + count( $posts );
        update_option( self::OFFSET_OPTION, $next, false );
        update_option( 'aikey_seo_geo_last_maintenance', gmdate( 'c' ), false );
    }

    public static function run_now( int $limit = 200 ): array {
        $self = new self();
        $limit = min( 500, max( 20, $limit ) );
        $posts = self::maintenance_batch( $limit, 0 );
        foreach ( $posts as $post ) {
            $self->apply_safe_defaults( $post );
            Audit_Module::persist( $post );
            Scorer::persist( $post );
        }
        update_option( 'aikey_seo_geo_last_maintenance', gmdate( 'c' ), false );
        return array( 'processed' => count( $posts ) );
    }

    private static function maintenance_batch( int $limit, int $offset ): array {
        return get_posts( array(
            'post_type'      => Utils::public_post_types(),
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ) );
    }

    private function apply_safe_defaults( \WP_Post $post ): void {
        $mode = (string) Settings::section_get( 'automation', 'mode', 'recommended' );
        if ( ! in_array( $mode, array( 'conservative', 'recommended', 'full' ), true ) ) { $mode = 'recommended'; }

        if ( Settings::section_get( 'automation', 'auto_schema', true ) && '' === Meta_Repository::schema_type( $post->ID ) ) {
            $type = $this->detect_schema_type( $post );
            if ( '' !== $type ) { update_post_meta( $post->ID, Meta_Repository::SCHEMA_TYPE, $type ); }
        }

        if ( 'full' === $mode && Settings::section_get( 'automation', 'fill_empty_entity', false ) ) {
            if ( '' === Meta_Repository::get( $post->ID, Meta_Repository::GEO_ENTITY ) ) {
                $entity = Meta_Repository::focus_keyword( $post->ID );
                if ( '' === $entity ) { $entity = get_the_title( $post ); }
                $entity = trim( wp_strip_all_tags( $entity ) );
                if ( '' !== $entity ) {
                    $entity = function_exists( 'mb_substr' ) ? mb_substr( $entity, 0, 100, 'UTF-8' ) : substr( $entity, 0, 100 );
                    update_post_meta( $post->ID, Meta_Repository::GEO_ENTITY, $entity );
                }
            }
            if ( '' === Meta_Repository::get( $post->ID, Meta_Repository::GEO_ENTITY_TYPE ) ) {
                $schema = Meta_Repository::schema_type( $post->ID );
                $map = array( 'Article'=>'Article', 'Product'=>'Product', 'Service'=>'Service' );
                if ( isset( $map[ $schema ] ) ) { update_post_meta( $post->ID, Meta_Repository::GEO_ENTITY_TYPE, $map[ $schema ] ); }
            }
        }
    }

    private function detect_schema_type( \WP_Post $post ): string {
        if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) { return 'Product'; }
        if ( 'post' === $post->post_type ) { return 'Article'; }

        $slug = strtolower( (string) $post->post_name );
        $title = (string) get_the_title( $post );
        if ( 'page' === $post->post_type ) {
            if ( preg_match( '/(^|[-_])(about|company|profile)([-_]|$)/i', $slug ) || preg_match( '/关于|公司简介|企业简介/u', $title ) ) { return 'AboutPage'; }
            if ( preg_match( '/(^|[-_])(contact|contact-us)([-_]|$)/i', $slug ) || preg_match( '/联系(我们)?/u', $title ) ) { return 'ContactPage'; }
        }
        $obj = get_post_type_object( $post->post_type );
        $label = $obj ? (string) $obj->labels->singular_name : '';
        if ( preg_match( '/服务|service/i', $post->post_type . ' ' . $label ) ) { return 'Service'; }
        return 'WebPage';
    }
}
