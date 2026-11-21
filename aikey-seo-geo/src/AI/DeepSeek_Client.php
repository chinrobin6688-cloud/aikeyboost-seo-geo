<?php
namespace AIKEY\SeoGeo\AI;

use AIKEY\SeoGeo\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class DeepSeek_Client {
    /**
     * Send a structured-output request to DeepSeek.
     *
     * DeepSeek documents that JSON Output can occasionally return an empty
     * content field. We therefore retry once with plain-text output while
     * still requiring a JSON object in the prompt.
     *
     * @param array<string,mixed> $options
     */
    public function analyze( string $system, string $user, array $options = array() ): array|\WP_Error {
        $key = trim( Settings::api_key() );
        if ( '' === $key ) { return new \WP_Error( 'missing_api_key', '尚未配置 DeepSeek API Key。' ); }

        $base = rtrim( (string) Settings::section_get( 'ai', 'base_url', 'https://api.deepseek.com' ), '/' );
        if ( ! wp_http_validate_url( $base ) || 'https' !== wp_parse_url( $base, PHP_URL_SCHEME ) ) {
            return new \WP_Error( 'invalid_base_url', 'AI API Base URL 必须是有效 HTTPS 地址。' );
        }

        $model = sanitize_text_field( (string) Settings::section_get( 'ai', 'model', 'deepseek-v4-flash' ) );
        $thinking = isset( $options['thinking'] ) ? (string) $options['thinking'] : 'enabled';
        if ( ! in_array( $thinking, array( 'enabled', 'disabled' ), true ) ) { $thinking = 'disabled'; }
        $max_tokens = isset( $options['max_tokens'] ) ? (int) $options['max_tokens'] : 4096;
        $max_tokens = max( 128, min( 8192, $max_tokens ) );
        $timeout = isset( $options['timeout'] ) ? (int) $options['timeout'] : 75;
        $timeout = max( 5, min( 75, $timeout ) );
        $retry = ! array_key_exists( 'retry', $options ) || (bool) $options['retry'];

        $first = $this->request_once( $base, $model, $key, $system, $user, true, $thinking, $max_tokens, $timeout );
        if ( is_wp_error( $first ) ) { return $first; }

        $parsed = $this->parse_json( (string) $first['content'] );
        if ( is_array( $parsed ) ) { return $parsed; }
        if ( ! $retry ) {
            return new \WP_Error( 'invalid_json_response', 'DeepSeek 返回了不可用的结构化结果，请稍后重试。' );
        }

        // DeepSeek JSON Output can occasionally return empty content or an
        // incomplete JSON payload. Retry once without response_format. This
        // avoids turning a transient provider behaviour into a user-facing
        // hard failure.
        $retry_system = $system . "\n重要：上一次结构化输出不可用。请只输出一个完整 JSON 对象，首字符必须是 {，末字符必须是 }，不要输出解释、Markdown 或代码围栏。";
        $second = $this->request_once( $base, $model, $key, $retry_system, $user, false, 'disabled', min( 8192, max( 6144, $max_tokens ) ), $timeout );
        if ( is_wp_error( $second ) ) { return $second; }

        $parsed = $this->parse_json( (string) $second['content'] );
        if ( is_array( $parsed ) ) { return $parsed; }

        $finish = sanitize_text_field( (string) ( $second['finish_reason'] ?: $first['finish_reason'] ) );
        if ( '' === trim( (string) $first['content'] ) && '' === trim( (string) $second['content'] ) ) {
            $suffix = '' !== $finish ? '（finish_reason: ' . $finish . '）' : '';
            return new \WP_Error( 'empty_response', 'DeepSeek 返回了空内容，插件已自动重试一次仍未获得有效结果' . $suffix . '。请稍后重试；若持续出现，请在 AI 设置中检查模型名称和 API Base URL。' );
        }

        return new \WP_Error( 'invalid_json_response', 'DeepSeek 返回了内容，但不是可解析的 JSON。插件已自动重试一次。请再次尝试；若持续出现，请检查模型设置。' );
    }

    /**
     * @return array{content:string,finish_reason:string,id:string}|\WP_Error
     */
    private function request_once( string $base, string $model, string $key, string $system, string $user, bool $json_mode, string $thinking, int $max_tokens, int $timeout ): array|\WP_Error {
        $body = array(
            'model' => $model,
            'messages' => array(
                array( 'role'=>'system','content'=>$system ),
                array( 'role'=>'user','content'=>$user ),
            ),
            'stream' => false,
            'max_tokens' => $max_tokens,
            'thinking' => array( 'type' => $thinking ),
        );

        if ( 'disabled' === $thinking ) {
            $body['temperature'] = 0.2;
        }
        if ( $json_mode ) {
            $body['response_format'] = array( 'type'=>'json_object' );
        }

        $response = wp_safe_remote_post( $base . '/chat/completions', array(
            'timeout' => $timeout,
            'redirection' => 2,
            'headers' => array( 'Content-Type'=>'application/json', 'Authorization'=>'Bearer ' . $key ),
            'body' => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
        ) );
        if ( is_wp_error( $response ) ) { return $response; }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw = (string) wp_remote_retrieve_body( $response );
        $json = json_decode( $raw, true );
        if ( $code < 200 || $code >= 300 ) {
            $message = is_array( $json ) ? sanitize_text_field( (string) ( $json['error']['message'] ?? 'AI API 请求失败。' ) ) : 'AI API 请求失败。';
            $message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 300, 'UTF-8' ) : substr( $message, 0, 600 );
            return new \WP_Error( 'api_error', $message, array( 'status'=>$code ) );
        }
        if ( ! is_array( $json ) ) {
            return new \WP_Error( 'invalid_api_response', 'AI API 返回了无法解析的响应。' );
        }

        $choice = isset( $json['choices'][0] ) && is_array( $json['choices'][0] ) ? $json['choices'][0] : array();
        $message = isset( $choice['message'] ) && is_array( $choice['message'] ) ? $choice['message'] : array();
        $content = $message['content'] ?? '';
        if ( is_array( $content ) ) {
            $parts = array();
            foreach ( $content as $part ) {
                if ( is_array( $part ) && isset( $part['text'] ) ) { $parts[] = (string) $part['text']; }
                elseif ( is_string( $part ) ) { $parts[] = $part; }
            }
            $content = implode( "\n", $parts );
        }

        $finish = sanitize_text_field( (string) ( $choice['finish_reason'] ?? '' ) );
        if ( in_array( $finish, array( 'content_filter', 'insufficient_system_resource' ), true ) && '' === trim( (string) $content ) ) {
            $message_text = 'content_filter' === $finish ? 'AI 服务未返回内容（内容过滤）。' : 'AI 服务推理资源暂时不足，请稍后重试。';
            return new \WP_Error( 'ai_finish_' . $finish, $message_text );
        }

        return array(
            'content' => (string) $content,
            'finish_reason' => $finish,
            'id' => sanitize_text_field( (string) ( $json['id'] ?? '' ) ),
        );
    }

    /** @return array<string,mixed>|null */
    private function parse_json( string $content ): ?array {
        if ( '' === trim( $content ) ) { return null; }
        $clean = trim( preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', trim( $content ) ) ?? $content );
        $decoded = json_decode( $clean, true );
        if ( is_array( $decoded ) ) { return $decoded; }
        $start = strpos( $clean, '{' );
        $end = strrpos( $clean, '}' );
        if ( false !== $start && false !== $end && $end > $start ) {
            $decoded = json_decode( substr( $clean, $start, $end - $start + 1 ), true );
            if ( is_array( $decoded ) ) { return $decoded; }
        }
        return null;
    }
}
