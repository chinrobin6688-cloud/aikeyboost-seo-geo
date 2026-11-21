<?php
namespace AIKEY\SeoGeo\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Optional Google Search Console Search Analytics reader.
 * Admin-only authorization-code OAuth; never a public REST endpoint or cron.
 * Google account credentials and consent never pass through WordPress.
 */
final class Google_Search_Console {
    private const CLIENT_ID = 'aikey_gsc_client_id';
    private const CLIENT_SECRET = 'aikey_gsc_client_secret_encrypted';
    private const REFRESH_TOKEN = 'aikey_gsc_refresh_encrypted';
    private const GRANT_MARKER = 'aikey_gsc_grant_marker';
    private const SITE = 'aikey_gsc_property';
    private const CACHE = 'aikey_gsc_search_analytics';
    private const LOCK = 'aikey_gsc_request_lock';
    private const NOTICE = 'aikey_gsc_notice_';
    private const STATE_PREFIX = 'aikey_gsc_oauth_';
    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN = 'https://oauth2.googleapis.com/token';
    private const REVOKE = 'https://oauth2.googleapis.com/revoke';
    private const PAGE = 'aikey-seo-geo-webmaster-data';

    public static function register_hooks(): void {
        if ( ! is_admin() ) { return; }
        add_action( 'admin_post_aikey_gsc_save', array( self::class, 'save' ) );
        add_action( 'admin_post_aikey_gsc_authorize', array( self::class, 'authorize' ) );
        add_action( 'admin_post_aikey_gsc_callback', array( self::class, 'callback' ) );
        add_action( 'admin_post_aikey_gsc_refresh', array( self::class, 'refresh' ) );
        add_action( 'admin_post_aikey_gsc_disconnect', array( self::class, 'disconnect' ) );
    }

    private static function require_admin( string $nonce = '' ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( '没有管理权限。', 'aikey-seo-geo' ), '', array( 'response'=>403 ) );
        }
        if ( '' !== $nonce ) { check_admin_referer( $nonce ); }
    }

    private static function redirect_uri(): string {
        return admin_url( 'admin-post.php?action=aikey_gsc_callback' );
    }

    public static function client_id(): string {
        return (string) get_option( self::CLIENT_ID, '' );
    }

    private static function client_secret(): string {
        return Webmaster_Data::decrypt_key( (string) get_option( self::CLIENT_SECRET, '' ) );
    }

    private static function refresh_token(): string {
        return Webmaster_Data::decrypt_key( (string) get_option( self::REFRESH_TOKEN, '' ) );
    }

    public static function connected(): bool {
        return '' !== self::client_id() && '' !== self::client_secret() && '' !== self::refresh_token();
    }

    public static function validate_property( string $site ): string {
        $site = trim( $site );
        if ( strlen( $site ) > 512 || '' === $site ) { return ''; }
        $home_host = strtolower( trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ), '[]' ) );
        if ( '' === $home_host ) { return ''; }
        if ( str_starts_with( $site, 'sc-domain:' ) ) {
            $domain = strtolower( substr( $site, 10 ) );
            if ( ! preg_match( '/^(?:[a-z0-9-]+\.)+[a-z0-9-]{2,}$/D', $domain ) ) { return ''; }
            if ( $home_host !== $domain && ! str_ends_with( $home_host, '.' . $domain ) ) { return ''; }
            return 'sc-domain:' . $domain;
        }
        return Webmaster_Data::validate_site( $site );
    }

    public static function property(): string {
        $saved = get_option( self::SITE, '' );
        return self::validate_property( is_string( $saved ) ? $saved : '' )
            ?: self::validate_property( home_url( '/' ) );
    }

    private static function announce( string $type, string $message ): void {
        set_transient( self::NOTICE . get_current_user_id(), array( 'type'=>$type, 'message'=>$message ), 60 );
    }

    private static function back(): void {
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
        exit;
    }

    /** Save per-site OAuth client; changing its identity drops old authorization. */
    public static function save(): void {
        self::require_admin( 'aikey_gsc_save' );
        $id = isset( $_POST['gsc_client_id'] ) && is_string( $_POST['gsc_client_id'] )
            ? trim( wp_unslash( $_POST['gsc_client_id'] ) ) : '';
        $secret = isset( $_POST['gsc_client_secret'] ) && is_string( $_POST['gsc_client_secret'] )
            ? trim( wp_unslash( $_POST['gsc_client_secret'] ) ) : '';
        $raw_property = isset( $_POST['gsc_property'] ) && is_string( $_POST['gsc_property'] )
            ? wp_unslash( $_POST['gsc_property'] ) : '';
        $site = self::validate_property( $raw_property );
        if ( ! preg_match( '/^[A-Za-z0-9._-]{8,200}\.apps\.googleusercontent\.com$/D', $id )
            || '' === $site || ( '' === $secret && '' === self::client_secret() ) ) {
            self::announce( 'error', 'Google OAuth Client ID、Client Secret 或站点属性无效；未修改原配置。' );
            self::back();
        }
        $sealed = '';
        if ( '' !== $secret ) {
            if ( strlen( $secret ) > 256 || ! preg_match( '/^[A-Za-z0-9._-]{8,256}$/D', $secret ) ) {
                self::announce( 'error', 'Google Client Secret 格式不正确。' );
                self::back();
            }
            $sealed = Webmaster_Data::encrypt_key( $secret );
            if ( is_wp_error( $sealed ) ) {
                self::announce( 'error', $sealed->get_error_message() );
                self::back();
            }
        }
        $identity_changed = self::client_id() !== $id || ( '' !== $secret && $secret !== self::client_secret() );
        $changes = array( self::CLIENT_ID=>$id, self::SITE=>$site );
        if ( '' !== $sealed ) { $changes[ self::CLIENT_SECRET ] = $sealed; }
        if ( ! Verified_Option_Changes::apply( $changes ) ) {
            // Rollback is best effort: if storage is unhealthy, revoke local
            // access rather than risk a token linked to a mixed client.
            self::clear_local_authorization();
            self::announce( 'error', 'Google 设置写入验证失败；已尝试恢复旧配置并清除本地授权。' );
            self::back();
        }
        if ( $identity_changed && ! self::clear_local_authorization() ) {
            self::announce( 'error', 'Google 客户端已变更，但旧本地令牌未能确认删除；请检查数据库权限。' );
            self::back();
        }
        delete_transient( self::CACHE );
        self::announce( 'success', $identity_changed ? 'Google 客户端设置已保存；请重新授权。' : 'Google 站点属性已保存。' );
        self::back();
    }

    private static function persist( string $name, string $value ): bool {
        update_option( $name, $value, false );
        return get_option( $name, null ) === $value;
    }

    public static function authorization_url( string $state ): string {
        return self::AUTHORIZE . '?' . http_build_query( array(
            'client_id'=>self::client_id(),
            'redirect_uri'=>self::redirect_uri(),
            'response_type'=>'code',
            'scope'=>self::SCOPE,
            'state'=>$state,
            'access_type'=>'offline',
            'prompt'=>'consent',
        ), '', '&', PHP_QUERY_RFC3986 );
    }

    public static function authorize(): void {
        self::require_admin( 'aikey_gsc_authorize' );
        if ( ! str_starts_with( self::redirect_uri(), 'https://' )
            || '' === self::client_id() || '' === self::client_secret() ) {
            self::announce( 'error', '请先保存 Google OAuth 客户端设置，并确保后台回调网址使用 HTTPS。' );
            self::back();
        }
        try { $state = bin2hex( random_bytes( 32 ) ); }
        catch ( \Throwable $e ) {
            self::announce( 'error', '安全随机数不可用，未发起授权。' );
            self::back();
        }
        $saved = set_transient( self::STATE_PREFIX . hash( 'sha256', $state ), array(
            'user_id'=>get_current_user_id(),
            'client_id'=>self::client_id(),
            'created'=>time(),
        ), 10 * MINUTE_IN_SECONDS );
        if ( ! $saved ) {
            self::announce( 'error', '无法保存 OAuth 会话状态，未发起授权。' );
            self::back();
        }
        // Destination is a hard-coded Google endpoint, not a user-supplied redirect.
        wp_redirect( self::authorization_url( $state ), 302, 'AIKEY SEO&GEO' );
        exit;
    }

    /**
     * Consume before validation: one-time, user-bound and client-bound OAuth CSRF token.
     * Exposed for deterministic regression testing; does not issue an API token.
     */
    public static function consume_state( string $state, int $user_id ): bool {
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $state ) || $user_id <= 0 ) { return false; }
        $key = self::STATE_PREFIX . hash( 'sha256', $state );
        $session = get_transient( $key );
        delete_transient( $key );
        $created = is_array( $session ) ? (int) ( $session['created'] ?? 0 ) : 0;
        return is_array( $session )
            && (int) ( $session['user_id'] ?? 0 ) === $user_id
            && hash_equals( (string) ( $session['client_id'] ?? '' ), self::client_id() )
            && $created > 0 && $created <= time() + 30 && time() - $created <= 600;
    }

    /** Callback is bound to the initiating administrator and one-time state. */
    public static function callback(): void {
        self::require_admin();
        $state = isset( $_GET['state'] ) && is_string( $_GET['state'] )
            ? wp_unslash( $_GET['state'] ) : '';
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $state ) ) {
            self::announce( 'error', 'Google OAuth state 无效，未交换授权码。' );
            self::back();
        }
        if ( ! self::consume_state( $state, get_current_user_id() ) ) {
            self::announce( 'error', '授权状态已过期或不属于当前管理员，请重新发起授权。' );
            self::back();
        }
        if ( isset( $_GET['error'] ) ) {
            self::announce( 'warning', 'Google 授权未完成；原连接未修改。' );
            self::back();
        }
        $code = isset( $_GET['code'] ) && is_string( $_GET['code'] ) ? wp_unslash( $_GET['code'] ) : '';
        if ( strlen( $code ) < 5 || strlen( $code ) > 2048 || preg_match( '/[\x00-\x20]/', $code ) ) {
            self::announce( 'error', 'Google 返回的授权码格式无效。' );
            self::back();
        }
        $tokens = self::token_request( array(
            'code'=>$code, 'client_id'=>self::client_id(), 'client_secret'=>self::client_secret(),
            'redirect_uri'=>self::redirect_uri(), 'grant_type'=>'authorization_code',
        ) );
        if ( is_wp_error( $tokens ) ) {
            self::announce( 'error', $tokens->get_error_message() );
            self::back();
        }
        $refresh = $tokens['refresh_token'] ?? '';
        if ( ! is_string( $refresh ) || '' === $refresh ) {
            self::announce( 'error', 'Google 未签发离线 refresh token；请在 Google 账户权限中检查授权后重试。' );
            self::back();
        }
        $sealed = Webmaster_Data::encrypt_key( $refresh );
        if ( is_wp_error( $sealed ) || ! self::persist( self::REFRESH_TOKEN, $sealed ) ) {
            self::announce( 'error', 'Google 令牌安全保存失败，未启用连接。' );
            self::back();
        }
        try { $marker = bin2hex( random_bytes( 16 ) ); }
        catch ( \Throwable $e ) { $marker = ''; }
        if ( '' === $marker || ! self::persist( self::GRANT_MARKER, $marker ) ) {
            self::clear_local_authorization();
            self::announce( 'error', 'Google 授权状态版本无法安全保存；已清理本地令牌，请重新授权。' );
            self::back();
        }
        delete_transient( self::CACHE );
        self::announce( 'success', 'Google 只读授权完成。可手动读取 Search Console 搜索表现。' );
        self::back();
    }

    /** Uses a fixed token endpoint, with no redirects and bounded responses. */
    private static function token_request( array $params ): array|\WP_Error {
        $response = wp_safe_remote_post( self::TOKEN, array(
            'timeout'=>8, 'redirection'=>0, 'limit_response_size'=>65536,
            'reject_unsafe_urls'=>true, 'cookies'=>array(),
            'headers'=>array( 'Accept'=>'application/json' ),
            'body'=>$params,
        ) );
        if ( is_wp_error( $response ) ) {
            return new \WP_Error( 'google_network', 'Google OAuth 服务网络请求失败。' );
        }
        if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return new \WP_Error( 'google_oauth', 'Google 拒绝令牌请求，可能是授权码过期、客户端配置或令牌失效。' );
        }
        $body = (string) wp_remote_retrieve_body( $response );
        if ( '' === $body || strlen( $body ) >= 65536 ) {
            return new \WP_Error( 'google_oauth_body', 'Google OAuth 响应为空或不完整。' );
        }
        $data = json_decode( $body, true );
        if ( ! is_array( $data ) || ! isset( $data['access_token'] ) || ! is_string( $data['access_token'] )
            || '' === $data['access_token'] || strlen( $data['access_token'] ) > 4096
            || ! isset( $data['expires_in'] ) || ! is_numeric( $data['expires_in'] )
            || (int) $data['expires_in'] < 30 || (int) $data['expires_in'] > 86400 ) {
            return new \WP_Error( 'google_oauth_schema', 'Google OAuth 响应结构无效。' );
        }
        if ( isset( $data['scope'] ) &&
            ! in_array( self::SCOPE, explode( ' ', trim( (string) $data['scope'] ) ), true ) ) {
            return new \WP_Error( 'google_scope', 'Google 没有授予 Search Console 只读权限。' );
        }
        return $data;
    }

    public static function get_access_token(): string|\WP_Error {
        $refresh = self::refresh_token();
        if ( '' === $refresh || '' === self::client_id() || '' === self::client_secret() ) {
            return new \WP_Error( 'google_not_connected', 'Google Search Console 尚未完成 OAuth 只读授权。' );
        }
        $tokens = self::token_request( array(
            'client_id'=>self::client_id(), 'client_secret'=>self::client_secret(),
            'refresh_token'=>$refresh, 'grant_type'=>'refresh_token',
        ) );
        if ( is_wp_error( $tokens ) ) { return $tokens; }
        // Google can rotate refresh tokens. Preserve the original if none is returned.
        if ( isset( $tokens['refresh_token'] ) && is_string( $tokens['refresh_token'] )
            && '' !== $tokens['refresh_token'] ) {
            $sealed = Webmaster_Data::encrypt_key( $tokens['refresh_token'] );
            if ( is_wp_error( $sealed ) || ! self::persist( self::REFRESH_TOKEN, $sealed ) ) {
                return new \WP_Error( 'google_rotation', 'Google 令牌轮换保存失败，已停止本次读取。' );
            }
        }
        return $tokens['access_token'];
    }

    /** @return array<int,array{date:string,clicks:int,impressions:int,ctr:float,position:float}>|\WP_Error */
    public static function fetch_stats(): array|\WP_Error {
        $access = self::get_access_token();
        if ( is_wp_error( $access ) ) { return $access; }
        $site = self::property();
        if ( '' === $site ) { return new \WP_Error( 'google_property', 'Google 站点属性无效。' ); }
        $endpoint = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site ) . '/searchAnalytics/query';
        // Search Console's date dimension and range are defined in Pacific time,
        // not in UTC or the site's local timezone.
        $pt_today = new \DateTimeImmutable( 'today', new \DateTimeZone( 'America/Los_Angeles' ) );
        $body = wp_json_encode( array(
            'startDate'=>$pt_today->modify( '-32 days' )->format( 'Y-m-d' ),
            'endDate'=>$pt_today->modify( '-3 days' )->format( 'Y-m-d' ),
            'dimensions'=>array( 'date' ), 'type'=>'web', 'rowLimit'=>40,
        ) );
        $response = wp_safe_remote_post( $endpoint, array(
            'timeout'=>8, 'redirection'=>0, 'limit_response_size'=>131072,
            'reject_unsafe_urls'=>true, 'cookies'=>array(),
            'headers'=>array( 'Authorization'=>'Bearer ' . $access, 'Content-Type'=>'application/json',
                'Accept'=>'application/json' ), 'body'=>$body,
        ) );
        if ( is_wp_error( $response ) ) {
            return new \WP_Error( 'google_network', 'Google Search Console 网络请求失败。' );
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status ) {
            return new \WP_Error( 'google_search_error',
                'Google Search Console API 返回 HTTP ' . $status . '；请检查站点权限、授权及 API 配额。' );
        }
        $json = (string) wp_remote_retrieve_body( $response );
        if ( '' === $json || strlen( $json ) >= 131072 ) {
            return new \WP_Error( 'google_partial', 'Google Search Console 响应为空或不完整。' );
        }
        return self::parse_stats( $json );
    }

    /** Validate every row; never generate or extrapolate missing metrics. */
    public static function parse_stats( string $json ): array|\WP_Error {
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) || ( isset( $data['rows'] ) && ! is_array( $data['rows'] ) ) ) {
            return new \WP_Error( 'google_schema', 'Google Search Analytics 响应结构无效。' );
        }
        $rows = $data['rows'] ?? array();
        if ( array_values( $rows ) !== $rows || count( $rows ) > 100 ) {
            return new \WP_Error( 'google_schema', 'Google 返回行结构异常。' );
        }
        $result = array();
        foreach ( $rows as $r ) {
            if ( ! is_array( $r ) || ! isset( $r['keys'][0], $r['clicks'], $r['impressions'], $r['ctr'], $r['position'] )
                || ! is_array( $r['keys'] ) || count( $r['keys'] ) !== 1 ) {
                return new \WP_Error( 'google_schema', 'Google 返回指标列缺失。' );
            }
            $date = (string) $r['keys'][0];
            $parts = explode( '-', $date );
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) || count( $parts ) !== 3
                || ! checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] )
                || isset( $result[ $date ] ) ) {
                return new \WP_Error( 'google_schema', 'Google 日期无效或重复。' );
            }
            foreach ( array( 'clicks', 'impressions', 'ctr', 'position' ) as $k ) {
                if ( ! is_numeric( $r[ $k ] ) || ! is_finite( (float) $r[ $k ] ) || (float) $r[ $k ] < 0 ) {
                    return new \WP_Error( 'google_schema', 'Google 返回不合法指标。' );
                }
            }
            $clicks = (float) $r['clicks'];
            $impressions = (float) $r['impressions'];
            if ( floor( $clicks ) !== $clicks || floor( $impressions ) !== $impressions
                || $clicks > 1e12 || $impressions > 1e12 || (float) $r['ctr'] > 1
                || (float) $r['position'] > 1e6 ) {
                return new \WP_Error( 'google_schema', 'Google 搜索指标超出允许范围。' );
            }
            $result[ $date ] = array(
                'date'=>$date, 'clicks'=>(int) $clicks, 'impressions'=>(int) $impressions,
                'ctr'=>(float) $r['ctr'], 'position'=>(float) $r['position'],
            );
        }
        krsort( $result, SORT_STRING );
        return array_slice( array_values( $result ), 0, 30 );
    }

    /** Reject stale in-flight results after reauthorization, disconnect or site changes. */
    public static function same_fetch_context( string $site, string $client_id, string $grant ): bool {
        return '' !== $site && $site === self::property()
            && $client_id === self::client_id() && self::connected()
            && hash_equals( $grant, (string) get_option( self::GRANT_MARKER, '' ) );
    }

    public static function refresh(): void {
        self::require_admin( 'aikey_gsc_refresh' );
        if ( get_transient( self::LOCK ) || ! set_transient( self::LOCK, 1, 30 ) ) {
            self::announce( 'warning', 'Google 数据读取过于频繁，请稍后刷新。' );
            self::back();
        }
        $site = self::property();
        $client_id = self::client_id();
        $grant = (string) get_option( self::GRANT_MARKER, '' );
        $rows = self::fetch_stats();
        if ( is_wp_error( $rows ) ) {
            self::announce( 'warning', $rows->get_error_message() );
            self::back();
        }
        if ( ! self::same_fetch_context( $site, $client_id, $grant ) ) {
            delete_transient( self::CACHE );
            self::announce( 'warning', 'Google 授权或站点属性在读取期间发生变化；已丢弃结果，请重新读取。' );
            self::back();
        }
        set_transient( self::CACHE, array( 'site'=>$site, 'fetched_at'=>time(), 'rows'=>$rows ),
            15 * MINUTE_IN_SECONDS );
        self::announce( 'success', '已读取 Google Search Console 官方网页搜索数据。' );
        self::back();
    }

    /**
     * Credentials must not be reported as removed until storage confirms it.
     * Some option backends may fail delete but allow an empty-string overwrite.
     */
    public static function clear_local_authorization(): bool {
        $cleared = true;
        foreach ( array( self::REFRESH_TOKEN, self::GRANT_MARKER ) as $option ) {
            delete_option( $option );
            if ( '' !== get_option( $option, '' ) ) {
                update_option( $option, '', false );
            }
            if ( '' !== get_option( $option, '' ) ) { $cleared = false; }
        }
        delete_transient( self::CACHE );
        return $cleared;
    }

    public static function disconnect(): void {
        self::require_admin( 'aikey_gsc_disconnect' );
        $refresh = self::refresh_token();
        // Remove local tokens first, regardless of external revocation success.
        $local_cleared = self::clear_local_authorization();
        $revoked = false;
        if ( '' !== $refresh ) {
            $response = wp_safe_remote_post( self::REVOKE, array(
                'timeout'=>5, 'redirection'=>0, 'limit_response_size'=>1024,
                'reject_unsafe_urls'=>true, 'cookies'=>array(),
                'headers'=>array( 'Content-Type'=>'application/x-www-form-urlencoded' ),
                'body'=>array( 'token'=>$refresh ),
            ) );
            $revoked = ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
        }
        self::announce( $local_cleared && $revoked ? 'success' : 'warning',
            ! $local_cleared
                ? 'Google 本地授权删除未能验证；请检查数据库，并在 Google 账号权限页撤销访问。'
                : ( $revoked ? 'Google 已撤销并清除本地授权。'
                    : '已清除本地 Google 授权；无法确认远端撤销，请到 Google 账号权限页核查。' ) );
        self::back();
    }

    public static function render_panel(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $uri = self::redirect_uri();
        $https = str_starts_with( $uri, 'https://' );
        echo '<h2>Google Search Console · 只读 OAuth</h2>';
        echo '<p>授权范围仅为 <code>webmasters.readonly</code>。使用自己的 Google Cloud Web OAuth 客户端，在 Cloud Console 中启用 Search Console API，并将以下完整回调 URI 添加到允许列表：</p>';
        echo '<p><code>' . esc_html( $uri ) . '</code></p>';
        if ( ! $https ) {
            echo '<div class="notice notice-warning inline"><p>当前回调地址不是 HTTPS，插件不会发起授权。请配置安全 HTTPS 站点后台后再继续。</p></div>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'aikey_gsc_save' );
        echo '<input type="hidden" name="action" value="aikey_gsc_save">';
        echo '<p><label>Google OAuth Client ID<br><input class="regular-text" name="gsc_client_id" value="'
            . esc_attr( self::client_id() ) . '" autocomplete="off" maxlength="250" required></label></p>';
        echo '<p><label>Google OAuth Client Secret（留空保留原有）<br>'
            . '<input class="regular-text" type="password" name="gsc_client_secret" value="" autocomplete="new-password" maxlength="256"></label></p>';
        echo '<p><label>Search Console 站点属性（同本站的 URL-prefix 或 sc-domain）<br>'
            . '<input class="regular-text" name="gsc_property" value="' . esc_attr( self::property() ) . '" required></label></p>';
        submit_button( '保存 Google OAuth 配置', 'secondary', 'submit', false );
        echo '</form><p>当前状态：<strong>' . ( self::connected() ? '已授权' : '未授权' )
            . '</strong>。更换 OAuth Client ID 或 Secret 将断开旧授权；站点属性必须在 Google 账号中有读取权限。</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'aikey_gsc_authorize' );
        echo '<input type="hidden" name="action" value="aikey_gsc_authorize">';
        submit_button( '前往 Google 完成只读授权', 'secondary', 'submit', false,
            array( 'disabled'=>! $https || '' === self::client_id() || '' === self::client_secret() ) );
        echo '</form>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'aikey_gsc_refresh' );
        echo '<input type="hidden" name="action" value="aikey_gsc_refresh">';
        submit_button( '手动读取 Google 官方搜索表现', 'primary', 'submit', false,
            array( 'disabled'=>! self::connected() ) );
        echo '</form>';
        if ( self::connected() ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'aikey_gsc_disconnect' );
            echo '<input type="hidden" name="action" value="aikey_gsc_disconnect">';
            submit_button( '断开 Google 授权', 'delete', 'submit', false );
            echo '</form>';
        }
        echo '<p><a target="_blank" rel="noopener noreferrer" href="https://console.cloud.google.com/apis/credentials">Google Cloud OAuth 设置</a> · '
            . '<a target="_blank" rel="noopener noreferrer" href="https://search.google.com/search-console">Search Console 官方后台</a></p>';

        $notice = get_transient( self::NOTICE . get_current_user_id() );
        if ( is_array( $notice ) ) {
            delete_transient( self::NOTICE . get_current_user_id() );
            $kind = in_array( (string) ( $notice['type'] ?? '' ), array( 'success', 'error', 'warning' ), true )
                ? (string) $notice['type'] : 'warning';
            echo '<div class="notice notice-' . esc_attr( $kind ) . '"><p>'
                . esc_html( (string) $notice['message'] ) . '</p></div>';
        }
        $cache = get_transient( self::CACHE );
        if ( is_array( $cache ) && self::connected() && ( $cache['site'] ?? '' ) === self::property() ) {
            echo '<h3>Google 官方网页搜索数据</h3>';
            echo '<p>上次成功读取：' . esc_html( wp_date( 'Y-m-d H:i', (int) $cache['fetched_at'] ) )
                . '；仅缓存 15 分钟。Google 可能存在数据延迟，点击、曝光、CTR、平均排名不等于 AI 引用。</p>';
            $rows = $cache['rows'] ?? array();
            if ( ! $rows ) { echo '<p>官方 API 本次未返回数据。</p>'; }
            else {
                echo '<table class="widefat striped"><thead><tr><th>日期（PT）</th><th>点击</th><th>曝光</th><th>CTR</th><th>平均排名</th></tr></thead><tbody>';
                foreach ( $rows as $row ) {
                    echo '<tr><td>' . esc_html( (string) $row['date'] ) . '</td><td>'
                        . esc_html( (string) $row['clicks'] ) . '</td><td>' . esc_html( (string) $row['impressions'] )
                        . '</td><td>' . esc_html( number_format_i18n( 100 * (float) $row['ctr'], 2 ) )
                        . '%</td><td>' . esc_html( number_format_i18n( (float) $row['position'], 1 ) ) . '</td></tr>';
                }
                echo '</tbody></table>';
            }
        } else {
            echo '<p>尚无缓存的 Google 搜索数据。只有完成授权并主动读取后才展示官方返回的指标。</p>';
        }
    }
}
