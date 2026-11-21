<?php
namespace AIKEY\SeoGeo\Integrations;

use AIKEY\SeoGeo\Core\Conflict_Detector;
use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\Support\Utils;

defined( 'ABSPATH' ) || exit;

final class Baidu_Submitter {
    public const SAFE_BATCH_LIMIT = 19;
    private const QUOTA_DATE_OPTION = 'aikey_seo_geo_baidu_over_quota_date';

    public static function submit_urls( array $urls, string $event = 'manual' ): array|\WP_Error {
        if ( ! Settings::module_enabled( 'baidu' ) ) { return new \WP_Error( 'baidu_disabled', '百度提交模块已关闭。' ); }
        if ( ! Conflict_Detector::frontend_allowed() ) { return new \WP_Error( 'conflict', '前台 SEO 接管未启用，暂不执行百度提交。' ); }
        if ( ! (bool) get_option( 'blog_public', true ) ) { return new \WP_Error( 'site_private', '站点当前设置为“建议搜索引擎不索引”，已停止百度主动提交。' ); }
        $site = untrailingslashit( trim( (string) Settings::section_get( 'baidu', 'site', '' ) ) );
        $token = trim( (string) Settings::section_get( 'baidu', 'token', '' ) );
        if ( '' === $site || '' === $token ) { return new \WP_Error( 'baidu_missing', '请先填写百度搜索资源平台的已验证站点与 API Token。' ); }
        if ( self::quota_paused_today() ) {
            return new \WP_Error( 'baidu_over_quota_cached', '百度今日主动提交额度已用完，Aikeyboost已暂停当天重复请求；次日会自动恢复并重试未提交 URL。' );
        }
        if ( ! wp_http_validate_url( $site ) || ! in_array( wp_parse_url( $site, PHP_URL_SCHEME ), array( 'http','https' ), true ) ) {
            return new \WP_Error( 'baidu_site_invalid', '百度已验证站点 URL 格式无效。' );
        }
        $site_host = strtolower( (string) wp_parse_url( $site, PHP_URL_HOST ) );
        $urls = array_values( array_unique( array_filter( array_map( static function( $u ) use ( $site_host ): string {
            $clean = esc_url_raw( (string) $u, array( 'http','https' ) );
            if ( '' === $clean ) { return ''; }
            $host = strtolower( (string) wp_parse_url( $clean, PHP_URL_HOST ) );
            return $host === $site_host ? $clean : '';
        }, $urls ) ) ) );
        $urls = array_slice( $urls, 0, self::SAFE_BATCH_LIMIT );
        if ( empty( $urls ) ) { return new \WP_Error( 'baidu_empty', '没有与百度已验证站点匹配的可提交 URL。' ); }

        // Baidu Search Resource Platform publishes the ordinary-submission endpoint over HTTP.
        // The API's `site` parameter must be the verified host (for example taimeifood.cn),
        // not the full URL with http:// or https://. Keep the full URL in settings for validation
        // and same-site URL filtering, but normalize the API parameter here.
        $api_site = $site_host;
        $endpoint = add_query_arg( array( 'site'=>$api_site, 'token'=>$token ), 'http://data.zz.baidu.com/urls' );
        $response = wp_safe_remote_post( $endpoint, array(
            'timeout'=>20,
            // Token is carried in the query string; never follow redirects to another host.
            'redirection'=>0,
            'headers'=>array( 'Content-Type'=>'text/plain; charset=UTF-8' ),
            'body'=>implode( "\n", $urls ),
        ) );
        if ( is_wp_error( $response ) ) {
            Submission_Log::add( 'baidu', count( $urls ), 0, false, $response->get_error_message(), $urls[0] ?? '' );
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        // Baidu commonly returns a JSON error body together with HTTP 4xx. Parse that first so
        // administrators see the real platform message (for example `site init fail`) instead
        // of a generic transport error.
        if ( is_array( $data ) && isset( $data['error'] ) ) {
            $error_code = (int) $data['error'];
            $message = trim( (string) ( $data['message'] ?? '' ) );
            $message = '' !== $message ? $message : '百度 API 返回错误。';
            $is_over_quota = false !== stripos( $message, 'over quota' );
            if ( $is_over_quota ) {
                update_option( self::QUOTA_DATE_OPTION, current_time( 'Y-m-d' ), false );
                $display = '百度今日主动提交额度已用完，Aikeyboost已暂停当天重复请求；次日自动恢复。';
            } else {
                $display = '百度 API 错误 ' . $error_code . '：' . $message;
            }
            Submission_Log::add( 'baidu', count( $urls ), $code, false, $display, $urls[0] ?? '' );
            return new \WP_Error( $is_over_quota ? 'baidu_over_quota' : 'baidu_api_error', $display, array( 'status'=>$code, 'response'=>$data ) );
        }

        if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
            $summary = trim( wp_strip_all_tags( $body ) );
            if ( function_exists( 'mb_substr' ) ) { $summary = mb_substr( $summary, 0, 180 ); }
            else { $summary = substr( $summary, 0, 180 ); }
            $message = '百度 API 返回异常（HTTP ' . $code . '）。';
            if ( '' !== $summary ) { $message .= ' 响应：' . $summary; }
            Submission_Log::add( 'baidu', count( $urls ), $code, false, $message, $urls[0] ?? '' );
            return new \WP_Error( 'baidu_api', $message, array( 'status'=>$code ) );
        }
        $success = (int) ( $data['success'] ?? 0 );
        delete_option( self::QUOTA_DATE_OPTION );
        Submission_Log::add( 'baidu', $success, $code, true, '百度普通收录 API：' . sanitize_key( $event ), $urls[0] ?? '' );
        return $data;
    }

    public static function quota_paused_today(): bool {
        $date = (string) get_option( self::QUOTA_DATE_OPTION, '' );
        if ( '' === $date ) { return false; }
        if ( $date !== current_time( 'Y-m-d' ) ) {
            delete_option( self::QUOTA_DATE_OPTION );
            return false;
        }
        return true;
    }

    public static function recent_urls( int $limit = self::SAFE_BATCH_LIMIT ): array {
        $limit = min( self::SAFE_BATCH_LIMIT, max( 1, $limit ) );
        $posts = get_posts( array(
            'post_type'=>Utils::public_post_types(),
            'post_status'=>'publish',
            // Fetch one extra because a static front page may duplicate home_url().
            'posts_per_page'=>min( 501, $limit + 1 ),
            'orderby'=>'modified',
            'order'=>'DESC',
            'no_found_rows'=>true,
        ) );
        $urls = array();
        $front_is_indexable = true;
        if ( 'page' === (string) get_option( 'show_on_front', 'posts' ) ) {
            $front_id = (int) get_option( 'page_on_front', 0 );
            if ( $front_id > 0 && 'noindex' === \AIKEY\SeoGeo\SEO\Meta\Meta_Repository::robots_index( $front_id ) ) {
                $front_is_indexable = false;
            }
        }
        if ( $front_is_indexable ) { $urls[] = home_url( '/' ); }
        foreach ( $posts as $post ) {
            if ( 'noindex' === \AIKEY\SeoGeo\SEO\Meta\Meta_Repository::robots_index( $post->ID ) ) { continue; }
            $url = get_permalink( $post );
            if ( $url ) { $urls[] = $url; }
        }
        return array_slice( array_values( array_unique( $urls ) ), 0, $limit );
    }
}
