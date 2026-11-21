<?php
/**
 * Plugin Name:       Aikeyboost SEO&GEO
 * Plugin URI:        https://aikeyboost.com/
 * Description:       SEO & GEO for Chinese WordPress site owners: Baidu/China search, global sitemaps, schema, multilingual hreflang and AI search readiness.
 * Version:           2.3.8
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Aikeyboost
 * Author URI:        https://aikeyboost.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aikey-seo-geo
 */

defined( 'ABSPATH' ) || exit;

define( 'AIKEY_SEO_GEO_VERSION', '2.3.8' );
define( 'AIKEY_SEO_GEO_DB_VERSION', '17' );
define( 'AIKEY_SEO_GEO_FILE', __FILE__ );
define( 'AIKEY_SEO_GEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIKEY_SEO_GEO_URL', plugin_dir_url( __FILE__ ) );

require_once AIKEY_SEO_GEO_DIR . 'src/Core/Autoloader.php';

AIKEY\SeoGeo\Core\Autoloader::register();

if ( ! function_exists( 'aikey_seo_geo_record_runtime_error' ) ) {
    function aikey_seo_geo_record_runtime_error( $scope, \Throwable $error ) {
        $message = sanitize_text_field( (string) $error->getMessage() );
        if ( function_exists( 'mb_substr' ) ) {
            $message = mb_substr( $message, 0, 300, 'UTF-8' );
        } else {
            $message = substr( $message, 0, 300 );
        }
        try {
            update_option(
                'aikey_seo_geo_last_runtime_error',
                array(
                    'version' => AIKEY_SEO_GEO_VERSION,
                    'scope'   => sanitize_key( (string) $scope ),
                    'type'    => get_class( $error ),
                    'message' => $message,
                    'file'    => basename( (string) $error->getFile() ),
                    'line'    => (int) $error->getLine(),
                    'time'    => gmdate( 'c' ),
                ),
                false
            );
        } catch ( \Throwable $ignored ) {
            // Error reporting itself must never turn a recoverable plugin error into a Fatal.
        }
        if ( function_exists( 'error_log' ) ) {
            error_log( '[Aikeyboost SEO&GEO ' . AIKEY_SEO_GEO_VERSION . '][' . sanitize_key( (string) $scope ) . '] ' . get_class( $error ) . ': ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }
}

if ( ! function_exists( 'aikey_seo_geo_safe_activate' ) ) {
    function aikey_seo_geo_safe_activate() {
        try {
            AIKEY\SeoGeo\Core\Activator::activate();
            delete_option( 'aikey_seo_geo_last_runtime_error' );
        } catch ( \Throwable $error ) {
            aikey_seo_geo_record_runtime_error( 'activation', $error );
            if ( function_exists( 'deactivate_plugins' ) ) {
                deactivate_plugins( plugin_basename( AIKEY_SEO_GEO_FILE ) );
            }
            wp_die(
                esc_html__( 'Aikeyboost SEO&GEO 激活时检测到运行异常，插件已保持停用，WordPress 数据未删除。请检查 PHP 错误日志或联系插件维护者。', 'aikey-seo-geo' ),
                esc_html__( 'Aikeyboost SEO&GEO 激活失败', 'aikey-seo-geo' ),
                array( 'back_link' => true )
            );
        }
    }
}

register_activation_hook( __FILE__, 'aikey_seo_geo_safe_activate' );
register_deactivation_hook( __FILE__, array( AIKEY\SeoGeo\Core\Deactivator::class, 'deactivate' ) );

add_action(
    'plugins_loaded',
    static function (): void {
        try {
            AIKEY\SeoGeo\Core\Plugin::instance()->boot();
        } catch ( \Throwable $error ) {
            aikey_seo_geo_record_runtime_error( 'boot', $error );
            if ( is_admin() ) {
                add_action(
                    'admin_notices',
                    static function () use ( $error ): void {
                        $code = substr( hash( 'sha256', get_class( $error ) . '|' . basename( (string) $error->getFile() ) . '|' . (int) $error->getLine() ), 0, 12 );
                        echo '<div class="notice notice-error"><p><strong>Aikeyboost SEO&amp;GEO 本次加载已安全中止。</strong> 为避免影响 WordPress 后台，插件捕获了运行异常。错误编号：<code>' . esc_html( $code ) . '</code>。请先停用插件并查看 PHP 错误日志。</p></div>';
                    }
                );
            }
        }
    },
    20
);
