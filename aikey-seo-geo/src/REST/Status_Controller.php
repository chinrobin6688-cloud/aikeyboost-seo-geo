<?php
namespace AIKEY\SeoGeo\REST;

use AIKEY\SeoGeo\Core\Conflict_Detector;
use AIKEY\SeoGeo\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Status_Controller {
    public function register(): void { add_action( 'rest_api_init', array( $this, 'routes' ) ); }
    public function routes(): void {
        register_rest_route( 'aikey-seo-geo/v1', '/status', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array( $this, 'status' ),
            'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
        ) );
    }
    public function status(): \WP_REST_Response {
        return rest_ensure_response( array(
            'plugin' => 'Aikeyboost SEO&GEO',
            'version' => AIKEY_SEO_GEO_VERSION,
            'db_version' => (string) get_option( 'aikey_seo_geo_db_version', '0' ),
            'status' => 'ok',
            'frontend_allowed' => Conflict_Detector::frontend_allowed(),
            'conflicts' => Conflict_Detector::active_seo_plugins(),
            'modules' => Settings::module_states(),
        ) );
    }
}
