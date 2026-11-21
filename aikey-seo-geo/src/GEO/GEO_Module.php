<?php
namespace AIKEY\SeoGeo\GEO;

use AIKEY\SeoGeo\Core\Module_Interface;
use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\Support\Utils;

defined( 'ABSPATH' ) || exit;

final class GEO_Module implements Module_Interface {
    public function id(): string { return 'geo'; }
    public function register(): void {
        add_action( 'save_post', array( $this, 'on_save' ), 90, 2 );
        Public_Content::register();
    }
    public function on_save( int $post_id, \WP_Post $post ): void {
        if ( Settings::module_enabled( 'automation' ) ) { return; }
        if ( wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status || ! in_array( $post->post_type, Utils::public_post_types(), true ) ) { return; }
        Scorer::persist( $post );
    }
}
