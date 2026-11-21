<?php
namespace AIKEY\SeoGeo\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * On-demand Bing Webmaster REST search statistics (not Bing AI Performance).
 *
 * Only the fixed, documented Bing JSON REST method can be called.
 * The user API key is encrypted at rest using WordPress salts and never logged
 * or echoed. Google Search Console requires a separate OAuth consent flow.
 */
final class Webmaster_Data {
    private const KEY_OPTION  = 'aikey_seo_geo_bing_webmaster_key';
    private const SITE_OPTION = 'aikey_seo_geo_bing_webmaster_site';
    private const CACHE_KEY   = 'aikey_seo_geo_bing_search_stats';
    private const LOCK_KEY    = 'aikey_seo_geo_bing_stats_lock';
    private const NOTICE_KEY  = 'aikey_seo_geo_webmaster_notice_';
    private const PAGE        = 'aikey-seo-geo-webmaster-data';

    public static function register_hooks(): void {
        if ( ! is_admin() ) { return; }
        add_action( 'admin_menu', array( self::class, 'menu' ), 33 );
        add_action( 'admin_post_aikey_webmaster_save_bing', array( self::class, 'save_bing' ) );
        add_action( 'admin_post_aikey_webmaster_refresh_bing', array( self::class, 'refresh_bing' ) );
    }

    public static function menu(): void {
        add_submenu_page(
            'aikey-seo-geo', '海外站长数据', '海外站长数据', 'manage_options',
            self::PAGE, array( self::class, 'render' )
        );
    }

    private static function require_admin( string $nonce ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( '没有管理权限。', 'aikey-seo-geo' ), '', array( 'response'=>403 ) );
        }
        check_admin_referer( $nonce );
    }

    /** The API key is encrypted and never stored in general SEO settings. */
    private static function encryption_key(): string {
        return hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), true );
    }

    public static function encrypt_key( string $api_key ): string|\WP_Error {
        if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) ) {
            return new \WP_Error( 'crypto_missing', '服务器缺少安全加密扩展，拒绝保存 API Key。' );
        }
        try { $iv = random_bytes( 12 ); }
        catch ( \Throwable $e ) { return new \WP_Error( 'random_failed', '无法生成安全随机数。' ); }
        $tag = '';
        $cipher = openssl_encrypt( $api_key, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );
        if ( ! is_string( $cipher ) || 16 !== strlen( $tag ) ) {
            return new \WP_Error( 'encryption_failed', 'API Key 加密失败。' );
        }
        return 'v1:' . base64_encode( $iv . $tag . $cipher );
    }

    public static function decrypt_key( string $stored ): string {
        if ( ! function_exists( 'openssl_decrypt' ) || ! str_starts_with( $stored, 'v1:' ) ) { return ''; }
        $raw = base64_decode( substr( $stored, 3 ), true );
        if ( ! is_string( $raw ) || strlen( $raw ) < 29 ) { return ''; }
        $key = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::encryption_key(),
            OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
        return is_string( $key ) ? $key : '';
    }

    public static function credential(): string {
        $stored = get_option( self::KEY_OPTION, '' );
        return is_string( $stored ) ? self::decrypt_key( $stored ) : '';
    }

    /** A Bing site property may be a path prefix, but it cannot target another domain. */
    public static function validate_site( string $site ): string {
        $site = trim( $site );
        if ( '' === $site || strlen( $site ) > 512 ) { return ''; }
        $parts = wp_parse_url( $site );
        $home = wp_parse_url( home_url( '/' ) );
        if ( ! is_array( $parts ) || ! is_array( $home ) ) { return ''; }
        if ( ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true )
            || strtolower( (string) ( $parts['host'] ?? '' ) ) !== strtolower( (string) ( $home['host'] ?? '' ) )
            || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] )
            || isset( $parts['port'] ) && (int) $parts['port'] !== (int) ( $home['port'] ?? ( 'https' === ( $home['scheme'] ?? '' ) ? 443 : 80 ) ) ) {
            return '';
        }
        // WordPress in a subdirectory may have a parent URL-prefix property.
        $path = '/' . ltrim( (string) ( $parts['path'] ?? '' ), '/' );
        if ( preg_match( '#/(?:\.{1,2})(?:/|$)#', $path ) ) { return ''; }
        return strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] )
            . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . $path;
    }

    public static function configured_site(): string {
        $site = get_option( self::SITE_OPTION, '' );
        $validated = is_string( $site ) ? self::validate_site( $site ) : '';
        return '' !== $validated ? $validated : self::validate_site( home_url( '/' ) );
    }

    private static function notice( string $type, string $message ): void {
        set_transient( self::NOTICE_KEY . get_current_user_id(),
            array( 'type'=>$type, 'message'=>$message ), 60 );
    }

    private static function back(): void {
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
        exit;
    }

    public static function save_bing(): void {
        self::require_admin( 'aikey_webmaster_save_bing' );
        $input = isset( $_POST['bing_site'] ) && is_string( $_POST['bing_site'] )
            ? wp_unslash( $_POST['bing_site'] ) : '';
        $site = self::validate_site( $input );
        if ( '' === $site ) {
            self::notice( 'error', '站点属性必须是当前网站主机名下的有效 URL，原有设置未修改。' );
            self::back();
        }
        $key = isset( $_POST['bing_api_key'] ) && is_string( $_POST['bing_api_key'] )
            ? trim( wp_unslash( $_POST['bing_api_key'] ) ) : '';
        $clear = isset( $_POST['bing_clear_key'] );
        if ( '' !== $key && $clear ) {
            self::notice( 'error', '不能同时提交新 API Key 和清除指令。' );
            self::back();
        }
        $encrypted = '';
        if ( '' !== $key ) {
            // Fixed-format printable secret; reject line breaks, delimiters and oversized payloads.
            if ( strlen( $key ) > 256 || ! preg_match( '/^[A-Za-z0-9_-]{16,256}$/D', $key ) ) {
                self::notice( 'error', 'API Key 格式不符合安全要求，原有设置未修改。' );
                self::back();
            }
            $encrypted = self::encrypt_key( $key );
            if ( is_wp_error( $encrypted ) ) {
                self::notice( 'error', $encrypted->get_error_message() );
                self::back();
            }
        }
        $changes = array( self::SITE_OPTION=>$site );
        if ( '' !== $encrypted ) { $changes[ self::KEY_OPTION ] = $encrypted; }
        elseif ( $clear ) { $changes[ self::KEY_OPTION ] = ''; }
        if ( ! Verified_Option_Changes::apply( $changes ) ) {
            // Even when rollback fails, never continue displaying old data
            // under an unknown combination of property and authorization.
            delete_option( self::KEY_OPTION );
            delete_transient( self::CACHE_KEY );
            self::notice( 'error', 'Bing 设置写入验证失败；已尝试恢复站点属性，并清除密钥以防止错配。' );
            self::back();
        }
        if ( $clear ) { delete_option( self::KEY_OPTION ); }
        delete_transient( self::CACHE_KEY );
        self::notice( 'success', $clear ? 'Bing API Key 已清除。' : 'Bing 配置已保存。没有自动访问第三方 API。' );
        self::back();
    }

    public static function refresh_bing(): void {
        self::require_admin( 'aikey_webmaster_refresh_bing' );
        if ( get_transient( self::LOCK_KEY ) ) {
            self::notice( 'warning', 'Bing 读取过于频繁；请稍后再次刷新。' );
            self::back();
        }
        if ( ! set_transient( self::LOCK_KEY, '1', 30 ) ) {
            self::notice( 'error', '无法记录限流状态，未请求第三方 API。' );
            self::back();
        }
        // Bind any fresh metrics to the exact property and credential present
        // before the outgoing HTTP request; an admin may change either meanwhile.
        $site = self::configured_site();
        $fingerprint = hash( 'sha256', self::credential() );
        $result = self::fetch_bing_stats();
        if ( is_wp_error( $result ) ) {
            self::notice( 'warning', $result->get_error_message() );
            self::back();
        }
        if ( ! self::same_fetch_context( $site, $fingerprint ) ) {
            delete_transient( self::CACHE_KEY );
            self::notice( 'warning', '读取期间 Bing 站点或密钥已改变；已丢弃结果，请重新读取。' );
            self::back();
        }
        set_transient( self::CACHE_KEY, array( 'site'=>$site,
            'fetched_at'=>time(), 'rows'=>$result ), 15 * MINUTE_IN_SECONDS );
        self::notice( 'success', '已读取 Bing Webmaster 官方站长流量（多垂直来源，不包含独立的 AI Performance 引用报表）。' );
        self::back();
    }

    /** A stale HTTP response must not be published under a changed Bing account/property. */
    public static function same_fetch_context( string $site, string $fingerprint ): bool {
        return '' !== $site && $site === self::configured_site()
            && '' !== self::credential()
            && hash_equals( $fingerprint, hash( 'sha256', self::credential() ) );
    }

    /** @return array<int,array{date:string,clicks:int,impressions:int}>|\WP_Error */
    public static function fetch_bing_stats(): array|\WP_Error {
        $key = self::credential();
        $site = self::configured_site();
        if ( '' === $key || '' === $site ) {
            return new \WP_Error( 'bing_not_connected', '尚未配置 Bing Webmaster API Key 和有效站点属性。' );
        }
        $url = 'https://www.bing.com/webmaster/api.svc/json/GetRankAndTrafficStats?'
            . http_build_query( array( 'siteUrl'=>$site, 'apikey'=>$key ), '', '&', PHP_QUERY_RFC3986 );
        $response = wp_safe_remote_get( $url, array(
            'timeout'=>8, 'redirection'=>0, 'limit_response_size'=>262144,
            'reject_unsafe_urls'=>true, 'cookies'=>array(),
            'headers'=>array( 'Accept'=>'application/json', 'Cache-Control'=>'no-cache' ),
        ) );
        if ( is_wp_error( $response ) ) {
            return new \WP_Error( 'bing_network_error', 'Bing API 请求失败，请检查服务器网络、TLS 或供应商可用性。' );
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status ) {
            return new \WP_Error( 'bing_api_error', 'Bing Webmaster API 返回 HTTP ' . $status . '；请核对 API Key、站点所有权和 API 状态。' );
        }
        $body = (string) wp_remote_retrieve_body( $response );
        if ( strlen( $body ) >= 262144 || '' === trim( $body ) ) {
            return new \WP_Error( 'bing_partial', 'Bing 响应为空或可能截断，未缓存结果。' );
        }
        return self::parse_bing_stats( $body );
    }

    /** @return array<int,array{date:string,clicks:int,impressions:int}>|\WP_Error */
    public static function parse_bing_stats( string $body ): array|\WP_Error {
        $data = json_decode( $body, true );
        if ( ! is_array( $data ) || ! isset( $data['d'] ) || ! is_array( $data['d'] ) || array_values( $data['d'] ) !== $data['d'] ) {
            return new \WP_Error( 'bing_schema', 'Bing API 响应结构无法验证，未生成搜索流量统计。' );
        }
        $rows = array();
        foreach ( $data['d'] as $row ) {
            if ( ! is_array( $row ) || ! preg_match( '#^/Date\((\d{11,14})(?:[+-]\d{4})?\)/$#D', (string) ( $row['Date'] ?? '' ), $date )
                || ! isset( $row['Clicks'], $row['Impressions'] )
                || ! is_numeric( $row['Clicks'] ) || ! is_numeric( $row['Impressions'] ) ) {
                return new \WP_Error( 'bing_schema', 'Bing 流量行包含无效日期或计数，未缓存结果。' );
            }
            $clicks = (float) $row['Clicks'];
            $impressions = (float) $row['Impressions'];
            if ( $clicks < 0 || $impressions < 0 || $clicks > 1e12 || $impressions > 1e12 ||
                floor( $clicks ) !== $clicks || floor( $impressions ) !== $impressions ) {
                return new \WP_Error( 'bing_schema', 'Bing 数据存在无效计数。' );
            }
            $timestamp = (int) floor( (float) $date[1] / 1000 );
            if ( $timestamp < 946684800 || $timestamp > time() + DAY_IN_SECONDS ) {
                return new \WP_Error( 'bing_schema', 'Bing 数据包含异常日期。' );
            }
            $ymd = gmdate( 'Y-m-d', $timestamp );
            if ( isset( $rows[ $ymd ] ) ) {
                return new \WP_Error( 'bing_schema', 'Bing 返回重复日期，已拒绝聚合避免重复统计。' );
            }
            $rows[ $ymd ] = array( 'date'=>$ymd, 'clicks'=>(int) $clicks, 'impressions'=>(int) $impressions );
        }
        krsort( $rows, SORT_STRING );
        return array_slice( array_values( $rows ), 0, 30 );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( '没有管理权限。', 'aikey-seo-geo' ), '', array( 'response'=>403 ) );
        }
        echo '<div class="wrap"><h1>海外站长数据</h1>';
        $notice = get_transient( self::NOTICE_KEY . get_current_user_id() );
        if ( is_array( $notice ) ) {
            delete_transient( self::NOTICE_KEY . get_current_user_id() );
            $kind = in_array( (string) ( $notice['type'] ?? '' ), array( 'error','success','warning' ), true )
                ? (string) $notice['type'] : 'warning';
            echo '<div class="notice notice-' . esc_attr( $kind ) . '"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>';
        }

        Global_Search_Dashboard::render();
        Google_Search_Console::render_panel();

        echo '<h2>Bing Webmaster Tools · 站长流量</h2>';
        echo '<p>使用 Bing 官方 REST 接口读取最多最近 30 个日期的点击和曝光。微软说明该指标涵盖 Web、Chat、News、Images 等多个垂直来源，不等同于纯网页搜索或 Copilot AI 引用数，也不包含完整 Bing AI Performance 报表。</p>';
        echo '<p>API Key 加密存放在独立 WordPress 配置项中；修改 WordPress 安全盐后需要重新配置。若只需手动查看，也可以不配置密钥，直接访问官方后台。</p>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'aikey_webmaster_save_bing' );
        echo '<input type="hidden" name="action" value="aikey_webmaster_save_bing">';
        echo '<p><label><strong>Bing 站点 URL（必须属于本站域名）</strong><br><input class="regular-text" name="bing_site" type="url" value="'
            . esc_attr( self::configured_site() ) . '" required></label></p>';
        echo '<p><label><strong>Bing API Key（留空保持原有密钥）</strong><br><input class="regular-text" name="bing_api_key" type="password" value="" autocomplete="new-password" maxlength="256"></label></p>';
        echo '<p>当前密钥状态：<strong>' . ( '' !== self::credential() ? '已配置' : '未配置' ) . '</strong></p>';
        echo '<p><label><input type="checkbox" name="bing_clear_key" value="1"> 清除已保存的 Bing API Key</label></p>';
        submit_button( '保存 Bing 配置', 'secondary', 'submit', false );
        echo '</form>';
        echo '<p><a href="https://www.bing.com/webmasters/" target="_blank" rel="noopener noreferrer">打开 Bing Webmaster Tools 获取 API Key</a></p>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'aikey_webmaster_refresh_bing' );
        echo '<input type="hidden" name="action" value="aikey_webmaster_refresh_bing">';
        submit_button( '手动读取 Bing 官方搜索数据', 'primary', 'submit', false );
        echo '</form>';

        $data = get_transient( self::CACHE_KEY );
        if ( is_array( $data ) && (string) ( $data['site'] ?? '' ) === self::configured_site() &&
            ! empty( $data['fetched_at'] ) && isset( $data['rows'] ) && is_array( $data['rows'] ) ) {
            $rows = $data['rows'];
            echo '<h3>上次成功获取的数据</h3><p>数据来源：Bing Webmaster API · 获取时间：'
                . esc_html( wp_date( 'Y-m-d H:i', (int) $data['fetched_at'] ) ) . '。仅缓存 15 分钟。</p>';
            if ( ! $rows ) { echo '<p>API 返回空列表，不代表网站遭到处罚。</p>'; }
            else {
                echo '<table class="widefat striped"><thead><tr><th>日期（UTC）</th><th>点击</th><th>曝光</th></tr></thead><tbody>';
                foreach ( $rows as $row ) {
                    echo '<tr><td>' . esc_html( (string) ( $row['date'] ?? '' ) ) . '</td><td>'
                        . esc_html( (string) (int) ( $row['clicks'] ?? 0 ) ) . '</td><td>'
                        . esc_html( (string) (int) ( $row['impressions'] ?? 0 ) ) . '</td></tr>';
                }
                echo '</tbody></table>';
            }
        } else {
            echo '<p>目前没有可展示的成功读取结果。尚未配置、API 失败或缓存过期时不会填入估算数据。</p>';
        }
        echo '</div>';
    }
}
