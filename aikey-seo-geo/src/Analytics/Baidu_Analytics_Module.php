<?php
namespace AIKEY\SeoGeo\Analytics;

use AIKEY\SeoGeo\Core\Module_Interface;
use AIKEY\SeoGeo\Core\Settings;

// Baidu Analytics / 百度统计 integration.
defined( 'ABSPATH' ) || exit;

final class Baidu_Analytics_Module implements Module_Interface {
    private static bool $rendered = false;

    public function id(): string { return 'baidu_analytics'; }

    public function register(): void {
        if ( '' === self::site_id() ) { return; }
        add_action( 'wp_head', array( $this, 'render' ), 99 );
    }

    public function render(): void {
        if ( self::$rendered || is_admin() || wp_doing_ajax() || wp_doing_cron() ) { return; }

        $site_id = self::site_id();
        if ( '' === $site_id ) { return; }

        $exclude_admins = (bool) Settings::section_get( 'baidu_analytics', 'exclude_admins', true );
        if ( $exclude_admins && is_user_logged_in() && current_user_can( 'manage_options' ) ) { return; }

        self::$rendered = true;
        $site_id_js = esc_js( $site_id );
        ?>
<!-- Aikeyboost SEO&GEO：百度统计（异步分析代码） -->
<script id="aikey-baidu-analytics">
var _hmt = _hmt || [];
(function() {
  var hm = document.createElement("script");
  hm.src = "https://hm.baidu.com/hm.js?<?php echo $site_id_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>";
  var s = document.getElementsByTagName("script")[0];
  s.parentNode.insertBefore(hm, s);
})();
</script>
        <?php
    }

    public static function site_id(): string {
        $site_id = trim( (string) Settings::section_get( 'baidu_analytics', 'site_id', '' ) );
        return preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $site_id ) ? $site_id : '';
    }

    /**
     * Accept either a safe Site ID or the full official Baidu Analytics snippet.
     * Only the ID is returned; arbitrary script content is never persisted.
     */
    public static function normalize_site_id_input( string $input ): string {
        $input = trim( $input );
        if ( '' === $input || strlen( $input ) > 8192 ) { return ''; }

        if ( preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $input ) ) {
            return $input;
        }

        if ( preg_match( '~(?:https?:)?//hm\.baidu\.com/hm\.js\?([A-Za-z0-9_-]{16,64})(?:["\'&<\s]|$)~i', $input, $match ) ) {
            return (string) $match[1];
        }

        return '';
    }

    /** @return array<string,int> */
    public static function extract_site_id_counts( string $html ): array {
        if ( '' === $html ) { return array(); }
        if ( ! preg_match_all( '~(?:https?:)?//hm\.baidu\.com/hm\.js\?([A-Za-z0-9_-]{16,64})~i', $html, $matches ) ) {
            return array();
        }
        $ids = array_values( array_filter( (array) ( $matches[1] ?? array() ), static fn( string $id ): bool => 1 === preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $id ) ) );
        return array_count_values( $ids );
    }

    /**
     * Read-only loopback check for the public home page. No visitor request performs this HTTP call.
     *
     * @return array{status:int,ids:array<int,string>,counts:array<string,int>,configured:string}|\WP_Error
     */
    public static function check_homepage(): array|\WP_Error {
        $url = home_url( '/' );
        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => 8,
                'redirection'         => 3,
                'limit_response_size' => 524288,
                'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml' ),
                'user-agent'          => 'AIQIBANG-SEO-GEO/Baidu-Analytics-Check',
            )
        );
        if ( is_wp_error( $response ) ) { return $response; }

        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 400 ) {
            return new \WP_Error( 'aikey_baidu_analytics_http', '公开首页返回 HTTP ' . $status . '，暂时无法确认百度统计安装状态。' );
        }

        $counts = self::extract_site_id_counts( (string) wp_remote_retrieve_body( $response ) );
        return array(
            'status'     => $status,
            'ids'        => array_keys( $counts ),
            'counts'     => $counts,
            'configured' => self::site_id(),
        );
    }
}
