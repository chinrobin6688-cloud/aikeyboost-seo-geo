<?php
namespace AIKEY\SeoGeo\Integrations;

use AIKEY\SeoGeo\SEO\Sitemap\Sitemap_Health;
use AIKEY\SeoGeo\SEO\International\International_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only, administrator-triggered public SEO / AI crawl diagnostics.
 * Not a bot emulator, indexing tester, or search-platform integration.
 */
final class Global_Diagnostics {
    private const MAX_BODY = 262144;
    private const COOLDOWN = 'aikey_global_diagnostics_cooldown';

    public static function register_hooks(): void {
        if ( ! is_admin() ) { return; }
        add_action( 'admin_menu', array( self::class, 'menu' ), 32 );
    }

    public static function menu(): void {
        add_submenu_page(
            'aikey-seo-geo', '全球搜索 / AI 抓取诊断', '全球抓取诊断',
            'manage_options', 'aikey-seo-geo-global-diagnostics', array( self::class, 'render' )
        );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( '没有权限执行诊断。', 'aikey-seo-geo' ), '', array( 'response'=>403 ) );
        }

        echo '<div class="wrap"><h1>全球 SEO / AI 抓取诊断</h1>';
        echo '<p>仅由管理员手动触发，最多读取本站公开首页与 robots.txt 两个网址；不会修改抓取规则、提交搜索引擎或请求第三方 AI 服务。</p>';
        echo '<p><strong>结果范围：</strong>只反映本次服务器请求看到的公网 HTTP/HTML 和 robots 规则；不能证明 Google、ChatGPT 等平台已抓取、索引或引用。User-Agent 不具备身份认证能力。</p>';
        echo '<form method="post">';
        wp_nonce_field( 'aikey_global_diagnostics_check', 'aikey_global_diagnostics_nonce' );
        echo '<input type="hidden" name="aikey_global_diagnostics_submit" value="1">';
        submit_button( '立即检查本站公开入口', 'secondary', 'submit', false );
        echo '</form>';

        if ( isset( $_POST['aikey_global_diagnostics_submit'] ) ) {
            if ( ! is_scalar( $_POST['aikey_global_diagnostics_submit'] ) ||
                ! isset( $_POST['aikey_global_diagnostics_nonce'] ) ||
                ! is_string( $_POST['aikey_global_diagnostics_nonce'] ) ||
                ! wp_verify_nonce(
                    sanitize_text_field( wp_unslash( $_POST['aikey_global_diagnostics_nonce'] ) ),
                    'aikey_global_diagnostics_check'
                ) ) {
                echo '<div class="notice notice-error"><p>诊断请求验证失败，未访问网络。</p></div>';
            } elseif ( get_transient( self::COOLDOWN ) ) {
                echo '<div class="notice notice-warning"><p>请求过于频繁，请稍后重试。每次诊断有 20 秒间隔。</p></div>';
            } elseif ( ! set_transient( self::COOLDOWN, 1, 20 ) ) {
                echo '<div class="notice notice-error"><p>无法记录诊断限流状态，未访问网络。</p></div>';
            } else {
                self::render_results( self::run() );
            }
        }
        self::render_existing_sitemap_status();
        echo '</div>';
    }

    /** Use only the fixed public site URLs; no user-provided URL or User-Agent. */
    public static function run(): array {
        $home_url = home_url( '/' );
        // robots.txt is always scoped to the origin root, not a WordPress subdirectory.
        $scheme = strtolower( (string) wp_parse_url( $home_url, PHP_URL_SCHEME ) );
        $host = (string) wp_parse_url( $home_url, PHP_URL_HOST );
        $port = wp_parse_url( $home_url, PHP_URL_PORT );
        $robots_url = $scheme . '://' . $host . ( null === $port ? '' : ':' . (int) $port ) . '/robots.txt';
        $home = self::read_public_url( $home_url, 'html' );
        $robots = self::read_public_url( $robots_url, 'robots' );
        $out = array(
            'home_url'   => $home_url,
            'robots_url' => $robots_url,
            'home'       => array( 'status'=>'unknown', 'message'=>'未获得可解析的首页响应。' ),
            'robots'     => array( 'status'=>'unknown', 'message'=>'未获得有效的 robots.txt。' ),
            'bots'       => array(),
        );
        if ( isset( $home['error'] ) ) {
            $out['home']['message'] = (string) $home['error'];
        } else {
            $out['home'] = self::inspect_home_html( (string) $home['body'], $home_url, (string) $home['x_robots'] );
        }
        if ( ! (bool) get_option( 'blog_public', true ) ) {
            $out['home'] = array(
                'status'=>'blocked',
                'message'=>'WordPress 已设置“不建议搜索引擎索引本站”；优先核对全站索引设置，不能根据公开 HTML 推断已经放行。',
            );
        }
        if ( isset( $robots['error'] ) ) {
            $out['robots']['message'] = (string) $robots['error'];
            return $out;
        }

        $body = (string) $robots['body'];
        $out['robots'] = array(
            'status'=>'observed',
            'message'=>'已读取公开 robots.txt；下表仅判断本站首页路径，不代表全站所有 URL 均可抓取。',
        );
        $path = (string) wp_parse_url( $home_url, PHP_URL_PATH );
        if ( '' === $path ) { $path = '/'; }
        $expected_blocks = China_Engine_Module::blocked_bots();
        foreach ( self::target_bots() as $token => $category ) {
            $analysis = self::evaluate_robots( $body, $token, $path );
            $expected = in_array( $token, $expected_blocks, true );
            $analysis['expected_block'] = $expected;
            if ( $expected && 'blocked' !== $analysis['status'] ) {
                $analysis['message'] .= '；插件设置要求阻止，但公网 robots 未观察到相同效果，请检查物理文件与缓存。';
                $analysis['mismatch'] = true;
            }
            $analysis['category'] = $category;
            $out['bots'][ $token ] = $analysis;
        }
        return $out;
    }

    /** Fixed same-site checks; unknown/redirected/partial responses are never counted as success. */
    private static function read_public_url( string $url, string $kind ): array {
        $host = trim( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ), '[]' );
        // WordPress can exempt same-host loopback URLs, so reject literal private IPs ourselves.
        $public_ip = filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
        if ( 'localhost' === $host || str_ends_with( $host, '.localhost' )
            || ( filter_var( $host, FILTER_VALIDATE_IP ) && false === $public_ip )
            || ! wp_http_validate_url( $url ) ) {
            return array( 'error'=>'本站网址未通过 WordPress 安全 HTTP 校验（如内网/回环地址）。无法进行公网诊断。' );
        }
        $response = wp_safe_remote_get( $url, array(
            'timeout'=>5, 'redirection'=>0, 'limit_response_size'=>self::MAX_BODY,
            'reject_unsafe_urls'=>true,
            'cookies'=>array(),
            'headers'=>array( 'Accept'=>'robots' === $kind ? 'text/plain' : 'text/html,application/xhtml+xml', 'Cache-Control'=>'no-cache' ),
        ) );
        if ( is_wp_error( $response ) ) {
            return array( 'error'=>'公开请求失败或被安全策略阻止，结果未知；请检查 DNS、回源与 CDN。' );
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status ) {
            return array( 'error'=>'HTTP ' . $status . '（必须返回 200；重定向不自动跟随）。' );
        }
        $type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
        if ( 'robots' === $kind ? ! str_starts_with( $type, 'text/plain' ) : ! preg_match( '#^(?:text/html|application/xhtml\+xml)(?:\s*;|$)#', $type ) ) {
            return array( 'error'=>'响应 Content-Type 与目标不符，不能可靠解析。' );
        }
        $body = (string) wp_remote_retrieve_body( $response );
        if ( '' === trim( $body ) || strlen( $body ) >= self::MAX_BODY ) {
            return array( 'error'=>'响应为空或达到 256 KiB 上限，无法确认是否完整。' );
        }
        if ( 'robots' === $kind && preg_match( '/<html\b|<!doctype\s+html/i', $body ) ) {
            return array( 'error'=>'robots.txt 实际返回 HTML，无法解析抓取规则。' );
        }
        $x = wp_remote_retrieve_header( $response, 'x-robots-tag' );
        return array( 'body'=>$body, 'x_robots'=>is_array( $x ) ? implode( ', ', $x ) : (string) $x );
    }

    /**
     * Pure robots parser for a single URL path and advertised crawler token.
     * Applies longest matching user-agent group, longest path rule and Allow on ties.
     * It does not imply identity verification, crawling success or indexing.
     */
    public static function evaluate_robots( string $body, string $bot, string $path = '/' ): array {
        $groups = array();
        $agents = array();
        $rules = array();
        $flush = static function () use ( &$groups, &$agents, &$rules ): void {
            if ( $agents ) { $groups[] = array( 'agents'=>$agents, 'rules'=>$rules ); }
            $agents = array();
            $rules = array();
        };
        foreach ( preg_split( '/\r\n|\r|\n/', $body ) ?: array() as $line ) {
            $line = trim( (string) preg_replace( '/#.*$/', '', $line ) );
            if ( '' === $line ) { $flush(); continue; }
            if ( ! preg_match( '/^([A-Za-z-]+)\s*:\s*(.*?)\s*$/', $line, $matches ) ) { continue; }
            $key = strtolower( $matches[1] );
            $value = trim( $matches[2] );
            if ( 'user-agent' === $key ) {
                if ( $rules ) { $flush(); }
                if ( preg_match( '/^(?:\*|[A-Za-z0-9_.-]+)$/D', $value ) ) { $agents[] = strtolower( $value ); }
            } elseif ( ( 'allow' === $key || 'disallow' === $key ) && $agents && '' !== $value ) {
                $rules[] = array( 'type'=>$key, 'path'=>$value );
            }
        }
        $flush();

        $chosen = array();
        $specificity = -1;
        foreach ( $groups as $group ) {
            foreach ( $group['agents'] as $agent ) {
                $match = '*' === $agent || str_starts_with( strtolower( $bot ), $agent );
                if ( ! $match ) { continue; }
                $score = '*' === $agent ? 0 : strlen( $agent );
                if ( $score > $specificity ) { $chosen = array(); $specificity = $score; }
                if ( $score === $specificity ) { $chosen[] = $group; }
            }
        }
        if ( ! $chosen ) {
            return array( 'status'=>'no_direct_block', 'message'=>'未发现匹配的 User-agent 规则，不能据此证明爬虫可访问。' );
        }

        $winner = null;
        $length = -1;
        foreach ( $chosen as $group ) {
            foreach ( $group['rules'] as $rule ) {
                $pattern = $rule['path'];
                $end = str_ends_with( $pattern, '$' );
                if ( $end ) { $pattern = substr( $pattern, 0, -1 ); }
                $regex = str_replace( '\*', '.*', preg_quote( $pattern, '~' ) );
                if ( ! preg_match( '~^' . $regex . ( $end ? '$' : '' ) . '~', $path ) ) { continue; }
                $weight = strlen( str_replace( '*', '', $pattern ) );
                if ( $weight > $length || ( $weight === $length && 'allow' === $rule['type'] ) ) {
                    $length = $weight;
                    $winner = $rule;
                }
            }
        }
        if ( null !== $winner && 'disallow' === $winner['type'] ) {
            return array( 'status'=>'blocked', 'message'=>'当前首页路径匹配 Disallow: ' . $winner['path'] );
        }
        return array( 'status'=>'no_direct_block', 'message'=>null === $winner
            ? '匹配到 User-agent，但没有阻止当前首页路径的规则。'
            : '当前首页路径匹配 Allow: ' . $winner['path'] . '；仍需验证其他访问条件。' );
    }

    /** Only generic robots directives are treated as site-wide noindex. */
    public static function inspect_home_html( string $html, string $url, string $x_robots = '' ): array {
        if ( ! class_exists( '\DOMDocument' ) ) {
            return array( 'status'=>'unknown', 'message'=>'服务器未安装 DOM 扩展，无法核验首页 HTML。' );
        }
        $before = libxml_use_internal_errors( true );
        try {
            $dom = new \DOMDocument();
            if ( ! $dom->loadHTML( $html, LIBXML_NONET ) ) {
                return array( 'status'=>'unknown', 'message'=>'首页 HTML 无法解析。' );
            }
            $xpath = new \DOMXPath( $dom );
            $canonical = array();
            $generic_noindex = false;
            $specific = array();
            foreach ( $xpath->query( '//head/link[@href]' ) as $node ) {
                $rels = preg_split( '/\s+/', strtolower( trim( $node->getAttribute( 'rel' ) ) ) );
                if ( ! in_array( 'canonical', is_array( $rels ) ? $rels : array(), true ) ) { continue; }
                $canonical[] = \WP_Http::make_absolute_url( $node->getAttribute( 'href' ), $url );
            }
            foreach ( $xpath->query( '//head/meta[@name]' ) as $node ) {
                $name = strtolower( trim( $node->getAttribute( 'name' ) ) );
                if ( 'robots' !== $name && ! preg_match( '/^(?:googlebot|bingbot)$/', $name ) ) { continue; }
                $value = strtolower( $node->getAttribute( 'content' ) );
                if ( ! preg_match( '/(?:^|[\s,;])(?:noindex|none)(?=$|[\s,;])/i', $value ) ) { continue; }
                if ( 'robots' === $name ) { $generic_noindex = true; }
                else { $specific[] = $name; }
            }
            foreach ( preg_split( '/[\r\n,]+/', $x_robots ) ?: array() as $header ) {
                $header = strtolower( trim( $header ) );
                if ( ! preg_match( '/(?:^|[\s,;])(?:noindex|none)(?=$|[\s,;])/i', $header ) ) { continue; }
                if ( preg_match( '/^[a-z][a-z0-9_-]*\s*:/', $header ) ) { $specific[] = 'X-Robots-Tag（指定爬虫）'; }
                else { $generic_noindex = true; }
            }
            if ( $generic_noindex ) {
                return array( 'status'=>'blocked', 'message'=>'页面 Meta robots 或响应头声明通用 noindex/none。' );
            }
            if ( 1 !== count( $canonical ) ) {
                return array( 'status'=>'review', 'message'=>count( $canonical ) > 1
                    ? '首页存在多个 Canonical，需检查主题/其他 SEO 插件重复输出。'
                    : '未发现单一 Canonical；这不一定阻止索引，但建议核验首页规范网址。' );
            }
            if ( International_Module::url_identity( $canonical[0] ) !== International_Module::url_identity( $url ) ) {
                return array( 'status'=>'review', 'message'=>'Canonical 指向非本次检查的首页 URL，需核对规范网址和跳转设置。' );
            }
            return array( 'status'=> $specific ? 'review' : 'no_direct_block',
                'message'=>$specific
                    ? '通用 noindex 未发现，但存在定向 noindex：' . implode( '、', array_unique( $specific ) ) . '。'
                    : '检测到自指 Canonical，未发现通用 noindex；不代表平台已收录。' );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors( $before );
        }
    }

    public static function target_bots(): array {
        return array(
            'Googlebot'=>'搜索', 'bingbot'=>'搜索',
            'OAI-SearchBot'=>'AI 搜索', 'ChatGPT-User'=>'用户请求', 'GPTBot'=>'模型训练',
            'Claude-SearchBot'=>'AI 搜索', 'Claude-User'=>'用户请求', 'ClaudeBot'=>'模型训练',
            'PerplexityBot'=>'AI 搜索', 'Perplexity-User'=>'用户请求',
            'Google-Extended'=>'AI 使用偏好',
        );
    }

    private static function render_results( array $result ): void {
        echo '<h2>本次公开入口检查</h2>';
        foreach ( array( 'home'=>'首页 HTML / Canonical / noindex', 'robots'=>'公开 robots.txt' ) as $key=>$label ) {
            $entry = $result[ $key ];
            echo '<p><strong>' . esc_html( $label ) . '：</strong>'
                . esc_html( (string) $entry['message'] ) . ' <code>' . esc_html( (string) $result[ $key . '_url' ] ) . '</code></p>';
        }
        if ( ! $result['bots'] ) {
            echo '<p>未获得可检查的公网 robots.txt，因此未推断任何平台的抓取权限。</p>';
            return;
        }
        echo '<h2>公开 robots 对本站首页路径的规则判断</h2>';
        echo '<table class="widefat striped"><thead><tr><th>爬虫 / 产品令牌</th><th>用途</th><th>观察结果</th></tr></thead><tbody>';
        foreach ( $result['bots'] as $token=>$entry ) {
            $status = 'blocked' === $entry['status'] ? '匹配阻止' : '未发现当前路径的直接阻止';
            echo '<tr><td><code>' . esc_html( $token ) . '</code></td><td>' . esc_html( $entry['category'] )
                . '</td><td><strong>' . esc_html( $status ) . '</strong> — ' . esc_html( $entry['message'] ) . '</td></tr>';
        }
        echo '</tbody></table><p>这里不模拟平台实际 User-Agent，不检查实时 IP 身份，也无法验证 CDN/WAF 对特定机器人是否放行。Google-Extended 是 robots 产品令牌，不是独立 HTTP 抓取器。</p>';
    }

    private static function render_existing_sitemap_status(): void {
        $last = Sitemap_Health::last_result();
        $checked = Sitemap_Health::last_run();
        echo '<h2>既有 Sitemap / robots 健康检查</h2>';
        if ( ! $checked || ! $last ) {
            echo '<p>尚无已保存的 Sitemap 健康结果；请在「Sitemap / robots」模块执行原有检查。</p>';
            return;
        }
        echo '<p>上次检查：' . esc_html( $checked ) . '（非本次重新发起）</p>';
        foreach ( array( 'sitemap'=>'Sitemap', 'robots'=>'robots.txt' ) as $key=>$label ) {
            echo '<p><strong>' . esc_html( $label ) . '：</strong>'
                . esc_html( (string) ( $last[$key]['message'] ?? '尚无结果' ) ) . '</p>';
        }
    }
}
