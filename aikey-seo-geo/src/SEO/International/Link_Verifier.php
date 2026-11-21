<?php
namespace AIKEY\SeoGeo\SEO\International;

use AIKEY\SeoGeo\GEO\Language;

defined( 'ABSPATH' ) || exit;

/** Explicit administrator-only inspection; never registered on a public request. */
final class Link_Verifier {
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        echo '<h2>跨站对应关系检查</h2><p>填写已配置对应关系的页面 ID 和目标语言，每次只检查一个真实网址。检查不会修改页面，不能证明搜索引擎已收录。</p>';
        echo '<form method="post">';
        wp_nonce_field( 'aikey_verify_alternate', 'aikey_verify_nonce' );
        echo '<label>页面 ID <input type="number" min="1" name="aikey_verify_id" required></label> ';
        echo '<label>目标语言 <input name="aikey_verify_language" placeholder="fr / ar / x-default" required></label> ';
        submit_button( '检查对应页面', 'secondary', 'aikey_verify_submit', false );
        echo '</form>';
        if ( ! isset( $_POST['aikey_verify_submit'] ) ) { return; }
        $result = $this->submitted_result();
        if ( isset( $result['error'] ) ) {
            echo '<p role="alert">' . esc_html( $result['error'] ) . '</p>';
            return;
        }
        echo '<p>检查地址：<code>' . esc_html( $result['url'] ) . '</code></p><ul>';
        foreach ( $result['checks'] as $check ) {
            echo '<li>' . esc_html( ( $check['ok'] ? '通过：' : '需处理：' ) . $check['label'] ) . '</li>';
        }
        echo '</ul><p>仅反映本次返回的 HTML 与响应头；CDN、地区或登录状态可能造成不同结果。robots.txt 与搜索引擎收录状态不在本次检查范围内。</p>';
    }

    /** Validate the request before reading a mapping or issuing network traffic. */
    public function submitted_result(): array {
        if ( ! current_user_can( 'manage_options' ) ) { return array( 'error'=>'没有检查权限。' ); }
        foreach ( array( 'aikey_verify_nonce', 'aikey_verify_id', 'aikey_verify_language' ) as $key ) {
            if ( ! isset( $_POST[$key] ) || ! is_string( $_POST[$key] ) ) { return array( 'error'=>'检查参数不合法。' ); }
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aikey_verify_nonce'] ) ), 'aikey_verify_alternate' ) ) {
            return array( 'error'=>'检查请求已过期，请刷新页面。' );
        }
        $id_raw = wp_unslash( $_POST['aikey_verify_id'] );
        if ( ! preg_match( '/^[1-9][0-9]{0,9}$/D', $id_raw ) ) { return array( 'error'=>'页面 ID 不合法。' ); }
        $id = (int) $id_raw;
        if ( ! current_user_can( 'edit_post', $id ) ) { return array( 'error'=>'没有该页面的编辑权限。' ); }
        $post = get_post( $id );
        if ( ! $post instanceof \WP_Post ) { return array( 'error'=>'页面不存在。' ); }
        $language = Language::normalize( wp_unslash( $_POST['aikey_verify_language'] ), true );
        $pairs = International_Module::alternate_links( $post );
        $self_language = Language::code( $post );
        if ( '' === $language || $language === $self_language || empty( $pairs[$language] ) ) {
            return array( 'error'=>'未找到可检查的对应关系，请确认已启用多语言功能且本页可索引。' );
        }
        if ( get_transient( 'aikey_i18n_check_cooldown' ) ) { return array( 'error'=>'请稍后再检查，每 15 秒最多检查一个网址。' ); }
        if ( ! set_transient( 'aikey_i18n_check_cooldown', 1, 15 ) ) { return array( 'error'=>'暂时无法保存检查限流状态，请稍后重试。' ); }
        return self::inspect( $pairs[$language], $language, $self_language, (string) get_permalink( $post ) );
    }

    public static function inspect( string $url, string $target_language, string $source_language, string $source_url ): array {
        if ( strlen( $url ) > 2048 || ! wp_http_validate_url( $url ) ) {
            return array( 'error'=>'目标网址未通过公开 HTTP 地址安全校验。' );
        }
        $response = wp_safe_remote_get( $url, array(
            'timeout'=>5, 'redirection'=>0, 'limit_response_size'=>524288,
            'cookies'=>array(), 'headers'=>array( 'Accept'=>'text/html, application/xhtml+xml' ),
        ) );
        if ( is_wp_error( $response ) ) { return array( 'error'=>'目标页面暂时无法访问或被安全策略拦截。' ); }
        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status ) { return array( 'error'=>'目标页面 HTTP 状态为 ' . $status . '；重定向不会自动跟随，请填写最终页面网址。' ); }
        $type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
        if ( ! preg_match( '#^(?:text/html|application/xhtml\+xml)(?:\s*;|$)#', $type ) ) {
            return array( 'error'=>'响应不是可检查的 HTML 页面。' );
        }
        $body = wp_remote_retrieve_body( $response );
        if ( strlen( $body ) >= 524288 ) { return array( 'error'=>'页面超过 512 KiB 检查上限，结果不完整，需人工核验。' ); }
        $robots = wp_remote_retrieve_header( $response, 'x-robots-tag' );
        $robots = is_array( $robots ) ? implode( ', ', $robots ) : (string) $robots;
        return self::analyze( $body, $url, $target_language, $source_language, $source_url, $robots );
    }

    /** Parse inert HTML only, without executing scripts or loading external entities. */
    public static function analyze( string $html, string $url, string $target_language, string $source_language, string $source_url, string $robots_header = '' ): array {
        if ( ! class_exists( '\DOMDocument' ) ) { return array( 'error'=>'服务器缺少 DOM 扩展，无法完成 HTML 检查。' ); }
        if ( '' === trim( $html ) || strlen( $html ) >= 524288 ) { return array( 'error'=>'HTML 为空或超过检查上限。' ); }
        $previous = libxml_use_internal_errors( true );
        try {
            $doc = new \DOMDocument();
            if ( ! $doc->loadHTML( $html, LIBXML_NONET ) ) { return array( 'error'=>'HTML 无法解析，请人工核验。' ); }
            $xpath = new \DOMXPath( $doc );
            $base = $url;
            $bases = $xpath->query( '//head/base[@href]' );
            if ( $bases && $bases->length ) { $base = \WP_Http::make_absolute_url( $bases->item(0)->getAttribute('href'), $url ); }
            $canonical = array();
            $alternates = array();
            foreach ( $xpath->query( '//head/link[@href]' ) as $link ) {
                $rels = preg_split( '/\s+/', strtolower( trim( $link->getAttribute('rel') ) ) ) ?: array();
                $href = \WP_Http::make_absolute_url( $link->getAttribute('href'), $base );
                if ( in_array( 'canonical', $rels, true ) ) { $canonical[] = $href; }
                if ( in_array( 'alternate', $rels, true ) ) {
                    $lang = Language::normalize( $link->getAttribute('hreflang'), true );
                    if ( '' !== $lang ) { $alternates[$lang][] = $href; }
                }
            }
            $robots = $robots_header;
            foreach ( $xpath->query( '//head/meta[@name]' ) as $meta ) {
                if ( in_array( strtolower( trim( $meta->getAttribute('name') ) ), array( 'robots','googlebot','bingbot' ), true ) ) {
                    $robots .= ', ' . $meta->getAttribute('content');
                }
            }
            $same = static fn( string $a, string $b ): bool => International_Module::url_identity($a) === International_Module::url_identity($b);
            $matches = static fn( array $links, string $expected ): bool => 1 === count($links) && $same($links[0], $expected);
            $root = $doc->getElementsByTagName('html')->item(0);
            $actual_language = $root ? Language::normalize( $root->getAttribute('lang') ) : '';
            $checks = array(
                array( 'ok'=>1 === count($canonical) && $same($canonical[0],$url), 'label'=>'目标页只有一个 Canonical，且指向目标页自身' ),
                array( 'ok'=>! preg_match( '/(?:^|[\s,:;])(?:noindex|none)(?=$|[\s,;])/i', $robots ), 'label'=>'响应头和页面 robots 未声明禁止索引' ),
                array( 'ok'=>$matches($alternates[$target_language] ?? array(), $url), 'label'=>'目标页包含正确的自指 hreflang' ),
                array( 'ok'=>$matches($alternates[$source_language] ?? array(), $source_url), 'label'=>'目标页包含指回本页的 hreflang' ),
            );
            if ( 'x-default' !== $target_language ) {
                $checks[] = array( 'ok'=>$actual_language === $target_language, 'label'=>'目标页 HTML lang 与配置语言一致' );
            }
            return array( 'url'=>$url, 'checks'=>$checks );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors( $previous );
        }
    }
}
