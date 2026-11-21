<?php
namespace AIKEY\SeoGeo\Integrations;

use AIKEY\SeoGeo\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Safe site ownership verification meta manager.
 *
 * Only <meta name="..." content="..."> pairs are accepted. Arbitrary HTML,
 * JavaScript and PHP are deliberately unsupported.
 */
final class Site_Verification {
    /**
     * Ownership verification must stay available independently of the optional
     * China-engine module and of SEO-plugin takeover/conflict protection.
     */
    public static function register_hooks(): void {
        add_action( 'wp_head', array( self::class, 'output_meta' ), 2 );
    }

    public static function output_meta(): void {
        if ( is_admin() || is_feed() || is_trackback() || ( function_exists( 'is_robots' ) && is_robots() ) ) { return; }
        $entries = self::configured_entries();
        if ( ! $entries ) { return; }
        echo "\n<!-- Aikeyboost SEO&GEO site verification -->\n";
        foreach ( $entries as $entry ) {
            printf(
                '<meta name="%s" content="%s" />' . "\n",
                esc_attr( (string) ( $entry['name'] ?? '' ) ),
                esc_attr( (string) ( $entry['content'] ?? '' ) )
            );
        }
    }

    public static function profiles(): array {
        return array(
            'baidu' => array(
                'label' => '百度搜索',
                'default_name' => 'baidu-site-verification',
                'region' => '中国大陆',
                'url' => 'https://ziyuan.baidu.com/site/index',
                'url_label' => '打开站点验证页',
            ),
            'sogou' => array(
                'label' => '搜狗搜索',
                'default_name' => 'sogou_site_verification',
                'region' => '中国大陆',
                'url' => 'https://zhanzhang.sogou.com/index.php/dashboard/index',
                'url_label' => '打开网站验证页',
                'note' => '搜狗不同页面/时期可能提供不同格式，建议优先粘贴平台当前给出的完整 Meta 代码，插件会保留实际 name。',
            ),
            'so360' => array(
                'label' => '360 搜索',
                'default_name' => '360-site-verification',
                'region' => '中国大陆',
                'url' => 'https://zhanzhang.so.com/sitetool/site_manage',
                'url_label' => '打开站点管理/验证',
            ),
            'shenma' => array(
                'label' => '神马搜索',
                'default_name' => 'shenma-site-verification',
                'region' => '中国大陆',
                'url' => 'https://zhanzhang.sm.cn/open/helpuser',
                'url_label' => '打开网站验证说明',
                'note' => '若站长平台给出的 Meta 名称与默认值不同，请直接粘贴完整代码，以平台实际代码为准。',
            ),
            'toutiao_search' => array(
                'label' => '头条 / 抖音搜索生态',
                'default_name' => 'bytedance-verification-code',
                'region' => '中国大陆',
                'url' => 'https://zhanzhang.toutiao.com/page/inner/site/manage',
                'url_label' => '打开站点管理/验证',
                'note' => '仅在对应平台实际提供 HTML Meta 验证代码时填写；建议粘贴完整代码。',
            ),
            'bing' => array(
                'label' => '必应 Bing',
                'default_name' => 'msvalidate.01',
                'region' => '国际 / 跨境',
                'url' => 'https://www.bing.com/webmasters/home',
                'url_label' => '打开站点验证控制台',
            ),
            'google' => array(
                'label' => 'Google Search Console',
                'default_name' => 'google-site-verification',
                'region' => '国际 / 跨境',
                'url' => 'https://search.google.com/search-console',
                'url_label' => '打开 Search Console 验证',
            ),
            'yandex' => array(
                'label' => 'Yandex Webmaster',
                'default_name' => 'yandex-verification',
                'region' => '国际 / 跨境',
                'url' => 'https://webmaster.yandex.com/sites/',
                'url_label' => '打开站点验证列表',
            ),
            'naver' => array(
                'label' => 'Naver Search Advisor',
                'default_name' => 'naver-site-verification',
                'region' => '国际 / 跨境',
                'url' => 'https://searchadvisor.naver.com/console/board',
                'url_label' => '打开站点验证控制台',
            ),
        );
    }

    /** @return array{name:string,content:string}|\WP_Error */
    public static function parse_single_input( string $raw, string $default_name ): array|\WP_Error {
        $raw = trim( wp_unslash( $raw ) );
        if ( '' === $raw ) { return array( 'name' => '', 'content' => '' ); }

        if ( false === stripos( $raw, '<meta' ) ) {
            if ( preg_match( '#^https?://#i', $raw ) ) {
                return new \WP_Error( 'verification_url_not_token', '这里应填写站点验证验证码或完整 Meta 标签，不能填写 API 地址、提交地址或普通网页 URL。' );
            }
            $name = self::sanitize_meta_name( $default_name );
            $content = self::sanitize_content( $raw );
            if ( '' === $name || '' === $content ) {
                return new \WP_Error( 'invalid_verification', '验证内容为空或格式无效。' );
            }
            return array( 'name' => $name, 'content' => $content );
        }

        if ( ! preg_match( '/<meta\b[^>]*>/i', $raw, $tag_match ) ) {
            return new \WP_Error( 'invalid_verification_meta', '没有识别到有效的 <meta> 验证标签。' );
        }
        $attrs = self::parse_attributes( $tag_match[0] );
        $name = self::sanitize_meta_name( (string) ( $attrs['name'] ?? '' ) );
        $content = self::sanitize_content( (string) ( $attrs['content'] ?? '' ) );
        if ( '' === $name || '' === $content ) {
            return new \WP_Error( 'invalid_verification_meta', 'Meta 验证代码必须同时包含 name 和 content。' );
        }
        return array( 'name' => $name, 'content' => $content );
    }

    /** @return array<int,array{name:string,content:string}>|\WP_Error */
    public static function parse_custom_input( string $raw ): array|\WP_Error {
        $raw = trim( wp_unslash( $raw ) );
        if ( '' === $raw ) { return array(); }
        if ( ! preg_match_all( '/<meta\b[^>]*>/i', $raw, $matches ) ) {
            return new \WP_Error( 'invalid_custom_verification', '其他验证代码中没有识别到 <meta> 标签。' );
        }
        $entries = array();
        foreach ( array_slice( $matches[0], 0, 20 ) as $tag ) {
            $attrs = self::parse_attributes( $tag );
            $name = self::sanitize_meta_name( (string) ( $attrs['name'] ?? '' ) );
            $content = self::sanitize_content( (string) ( $attrs['content'] ?? '' ) );
            if ( '' === $name || '' === $content ) { continue; }
            $entries[ strtolower( $name ) ] = array( 'name' => $name, 'content' => $content );
        }
        if ( ! $entries ) {
            return new \WP_Error( 'invalid_custom_verification', '其他验证代码必须是包含 name 和 content 的 Meta 标签。' );
        }
        return array_values( $entries );
    }

    /** @return array<int,array{name:string,content:string}> */
    public static function configured_entries(): array {
        $all = Settings::all();
        $section = isset( $all['site_verification'] ) && is_array( $all['site_verification'] ) ? $all['site_verification'] : array();
        $saved = isset( $section['entries'] ) && is_array( $section['entries'] ) ? $section['entries'] : array();
        $legacy = isset( $all['china_engines']['verification'] ) && is_array( $all['china_engines']['verification'] ) ? $all['china_engines']['verification'] : array();
        $entries = array();

        foreach ( self::profiles() as $id => $profile ) {
            $entry = isset( $saved[ $id ] ) && is_array( $saved[ $id ] ) ? $saved[ $id ] : array();
            $name = self::sanitize_meta_name( (string) ( $entry['name'] ?? '' ) );
            $content = self::sanitize_content( (string) ( $entry['content'] ?? '' ) );
            if ( '' === $content && isset( $legacy[ $id ] ) ) {
                $content = self::sanitize_content( (string) $legacy[ $id ] );
                $name = self::sanitize_meta_name( (string) ( $profile['default_name'] ?? '' ) );
            }
            if ( '' !== $name && '' !== $content ) {
                $entries[ strtolower( $name ) ] = array( 'name' => $name, 'content' => $content );
            }
        }

        $custom = isset( $section['custom'] ) && is_array( $section['custom'] ) ? $section['custom'] : array();
        foreach ( $custom as $entry ) {
            if ( ! is_array( $entry ) ) { continue; }
            $name = self::sanitize_meta_name( (string) ( $entry['name'] ?? '' ) );
            $content = self::sanitize_content( (string) ( $entry['content'] ?? '' ) );
            if ( '' === $name || '' === $content ) { continue; }
            $entries[ strtolower( $name ) ] = array( 'name' => $name, 'content' => $content );
        }
        return array_values( $entries );
    }

    public static function input_value( string $id ): string {
        $all = Settings::all();
        $profile = self::profiles()[ $id ] ?? null;
        if ( ! is_array( $profile ) ) { return ''; }
        $section = isset( $all['site_verification'] ) && is_array( $all['site_verification'] ) ? $all['site_verification'] : array();
        $entry = isset( $section['entries'][ $id ] ) && is_array( $section['entries'][ $id ] ) ? $section['entries'][ $id ] : array();
        $name = self::sanitize_meta_name( (string) ( $entry['name'] ?? '' ) );
        $content = self::sanitize_content( (string) ( $entry['content'] ?? '' ) );
        if ( '' === $content ) {
            $legacy = isset( $all['china_engines']['verification'][ $id ] ) ? self::sanitize_content( (string) $all['china_engines']['verification'][ $id ] ) : '';
            if ( '' !== $legacy ) { return $legacy; }
            return '';
        }
        if ( $name === (string) ( $profile['default_name'] ?? '' ) ) { return $content; }
        return '<meta name="' . $name . '" content="' . $content . '" />';
    }

    public static function custom_input_value(): string {
        $all = Settings::all();
        $custom = isset( $all['site_verification']['custom'] ) && is_array( $all['site_verification']['custom'] ) ? $all['site_verification']['custom'] : array();
        $lines = array();
        foreach ( $custom as $entry ) {
            if ( ! is_array( $entry ) ) { continue; }
            $name = self::sanitize_meta_name( (string) ( $entry['name'] ?? '' ) );
            $content = self::sanitize_content( (string) ( $entry['content'] ?? '' ) );
            if ( '' !== $name && '' !== $content ) {
                $lines[] = '<meta name="' . $name . '" content="' . $content . '" />';
            }
        }
        return implode( "\n", $lines );
    }

    /**
     * Best-effort cache purge after verification settings change.
     * This intentionally avoids hard dependencies on cache plugins.
     */
    public static function purge_caches(): void {
        $front_id = (int) get_option( 'page_on_front', 0 );
        if ( $front_id > 0 ) { clean_post_cache( $front_id ); }
        if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); }
        if ( function_exists( 'wp_cache_clear_cache' ) ) { wp_cache_clear_cache(); }
        if ( function_exists( 'w3tc_flush_all' ) ) { w3tc_flush_all(); }
        do_action( 'litespeed_purge_all' );
    }

    /**
     * Fetch the public homepage and verify that every configured meta pair is
     * present in the HTML source seen through WordPress HTTP API.
     *
     * @return array{url:string,status:int,checked:int,matched:int,missing:array<int,string>}|\WP_Error
     */
    public static function check_public_homepage(): array|\WP_Error {
        $entries = self::configured_entries();
        if ( ! $entries ) {
            return new \WP_Error( 'no_verification_entries', '尚未保存任何站点验证代码。' );
        }

        $url = home_url( '/' );
        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => 8,
                'redirection'         => 3,
                'limit_response_size' => 524288,
                'headers'             => array(
                    'Accept'     => 'text/html,application/xhtml+xml',
                    'User-Agent' => 'AIQibang-Verification-Check/' . AIKEY_SEO_GEO_VERSION,
                ),
            )
        );
        if ( is_wp_error( $response ) ) { return $response; }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $body   = (string) wp_remote_retrieve_body( $response );
        if ( $status < 200 || $status >= 400 || '' === $body ) {
            return new \WP_Error( 'homepage_fetch_failed', '读取公开首页失败，HTTP ' . $status . '。请检查站点访问、WAF/CDN或服务器回源设置。' );
        }

        $found = array();
        if ( preg_match_all( '/<meta\b[^>]*>/i', $body, $matches ) ) {
            foreach ( $matches[0] as $tag ) {
                $attrs = self::parse_attributes( $tag );
                $name = self::sanitize_meta_name( (string) ( $attrs['name'] ?? '' ) );
                $content = self::sanitize_content( (string) ( $attrs['content'] ?? '' ) );
                if ( '' !== $name && '' !== $content ) { $found[ strtolower( $name ) ] = $content; }
            }
        }

        $missing = array();
        $matched = 0;
        foreach ( $entries as $entry ) {
            $name = self::sanitize_meta_name( (string) ( $entry['name'] ?? '' ) );
            $content = self::sanitize_content( (string) ( $entry['content'] ?? '' ) );
            if ( '' !== $name && isset( $found[ strtolower( $name ) ] ) && hash_equals( $content, (string) $found[ strtolower( $name ) ] ) ) {
                ++$matched;
            } else {
                $missing[] = $name;
            }
        }

        return array(
            'url'     => $url,
            'status'  => $status,
            'checked' => count( $entries ),
            'matched' => $matched,
            'missing' => $missing,
        );
    }

    private static function parse_attributes( string $tag ): array {
        $attrs = array();
        if ( preg_match_all( '/([A-Za-z_:][-A-Za-z0-9_:.]*)\s*=\s*(["\'])(.*?)\2/s', $tag, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $attrs[ strtolower( $match[1] ) ] = html_entity_decode( $match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            }
        }
        return $attrs;
    }

    private static function sanitize_meta_name( string $name ): string {
        $name = trim( $name );
        if ( ! preg_match( '/^[A-Za-z0-9._:-]{1,100}$/', $name ) ) { return ''; }
        return $name;
    }

    private static function sanitize_content( string $content ): string {
        $content = trim( wp_strip_all_tags( $content ) );
        if ( '' === $content ) { return ''; }
        return function_exists( 'mb_substr' ) ? mb_substr( $content, 0, 1000 ) : substr( $content, 0, 1000 );
    }
}
