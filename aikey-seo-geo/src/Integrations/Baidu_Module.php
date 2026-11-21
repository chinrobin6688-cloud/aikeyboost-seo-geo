<?php
namespace AIKEY\SeoGeo\Integrations;

use AIKEY\SeoGeo\Core\Module_Interface;
use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\Support\Utils;
use AIKEY\SeoGeo\SEO\Sitemap\Sitemap_Content;

defined( 'ABSPATH' ) || exit;

final class Baidu_Module implements Module_Interface {
    public const LAST_SIG = '_aikey_baidu_last_submit_sig';

    /** @var array<int,string> */
    private array $pending_posts = array();
    private ?Sitemap_Content $indexable_content = null;

    public function id(): string { return 'baidu'; }

    public function register(): void {
        $enabled = false;
        if ( Settings::section_get( 'baidu', 'auto_submit', false ) ) {
            add_action( 'transition_post_status', array( $this, 'on_publish' ), 10, 3 );
            $enabled = true;
        }
        if ( Settings::section_get( 'baidu', 'auto_submit_updates', false ) ) {
            add_action( 'post_updated', array( $this, 'on_updated' ), 25, 3 );
            $enabled = true;
        }
        if ( $enabled ) {
            // Wait until Gutenberg/REST/meta-box fields are fully saved so a
            // newly selected noindex value is respected before submission.
            add_action( 'shutdown', array( $this, 'flush_pending' ), 25 );
        }
    }

    public function on_publish( string $new, string $old, \WP_Post $post ): void {
        if ( 'publish' !== $new || 'publish' === $old || ! $this->eligible( $post, false ) ) { return; }
        $this->pending_posts[ $post->ID ] = 'publish';
    }

    public function on_updated( int $post_id, \WP_Post $after, \WP_Post $before ): void {
        if ( 'publish' !== $after->post_status || 'publish' !== $before->post_status || ! $this->eligible( $after, false ) ) { return; }
        if ( $after->post_modified_gmt === $before->post_modified_gmt ) { return; }
        $this->pending_posts[ $post_id ] = 'update';
    }

    public function flush_pending(): void {
        if ( empty( $this->pending_posts ) ) { return; }
        $pending = $this->pending_posts;
        $this->pending_posts = array();
        foreach ( $pending as $post_id => $event ) {
            $post = get_post( (int) $post_id );
            if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! $this->eligible( $post, true ) ) { continue; }
            $this->submit_post( $post, $event );
        }
    }

    private function submit_post( \WP_Post $post, string $event ): void {
        $sig = Daily_Submission::post_signature( $post );
        if ( '' !== $sig && $sig === (string) get_post_meta( $post->ID, self::LAST_SIG, true ) ) { return; }
        $url = get_permalink( $post );
        if ( ! $url ) { return; }
        $result = Baidu_Submitter::submit_urls( array( $url ), $event );
        if ( ! is_wp_error( $result ) && (int) ( $result['success'] ?? 0 ) > 0 ) {
            update_post_meta( $post->ID, self::LAST_SIG, $sig );
        }
    }

    private function eligible( \WP_Post $post, bool $check_index = true ): bool {
        if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) { return false; }
        if ( ! in_array( $post->post_type, Utils::public_post_types(), true ) ) { return false; }
        if ( ! $check_index ) { return true; }
        $this->indexable_content ??= new Sitemap_Content();
        return '' !== $this->indexable_content->indexable_post_url( $post );
    }
}
