<?php
namespace AIKEY\SeoGeo\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Optional, token-level robots preferences for documented global AI crawlers.
 *
 * A user agent identifies an advertised robot, not a verified requester.
 * An "allow" choice merely removes this plugin's own Disallow; it does not
 * override robots.txt rules maintained by a site owner, web server or CDN.
 */
final class Global_Crawler_Policy {
    /** Public, documented robots tokens and their distinct purposes. */
    public static function profiles(): array {
        return array(
            'openai' => array(
                'OAI-SearchBot' => array( 'role'=>'search', 'description'=>'ChatGPT 搜索发现' ),
                'ChatGPT-User'  => array( 'role'=>'user', 'description'=>'ChatGPT 用户请求访问' ),
                'GPTBot'       => array( 'role'=>'training', 'description'=>'OpenAI 模型训练抓取' ),
            ),
            'claude' => array(
                'Claude-SearchBot' => array( 'role'=>'search', 'description'=>'Claude 搜索发现' ),
                'Claude-User'      => array( 'role'=>'user', 'description'=>'Claude 用户请求访问' ),
                'ClaudeBot'        => array( 'role'=>'training', 'description'=>'Anthropic 模型训练抓取' ),
            ),
            'perplexity' => array(
                'PerplexityBot'  => array( 'role'=>'search', 'description'=>'Perplexity 搜索发现' ),
                'Perplexity-User'=> array( 'role'=>'user', 'description'=>'Perplexity 用户请求访问' ),
            ),
            'google_ai' => array(
                'Google-Extended' => array( 'role'=>'usage', 'description'=>'Google AI 训练及部分模型 grounding 使用偏好；不是独立 HTTP User-Agent，不控制 Google 搜索收录' ),
            ),
        );
    }

    /** Restrict the preferences to tokens the central platform registry actually recognizes. */
    public static function for_platform( string $id ): array {
        $profiles = self::profiles()[ $id ] ?? array();
        if ( ! $profiles ) { return array(); }
        $registry = Engine_Registry::platforms();
        $known = (array) ( $registry[ $id ]['bots'] ?? array() );
        return array_filter(
            $profiles,
            static fn( string $bot ): bool => in_array( $bot, $known, true ),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Normalize untrusted form input. Only explicit overrides are stored.
     * A missing/invalid value inherits the existing platform-level policy.
     */
    public static function sanitize_policies( mixed $input ): array {
        if ( ! is_array( $input ) ) { return array(); }
        $out = array();
        foreach ( self::profiles() as $id => $bots ) {
            $submitted = $input[ $id ] ?? array();
            if ( ! is_array( $submitted ) ) { continue; }
            foreach ( self::for_platform( $id ) as $bot => $profile ) {
                $value = $submitted[ $bot ] ?? '';
                if ( ! is_string( $value ) ) { continue; }
                if ( 'allow' === $value || 'block' === $value ) {
                    $out[ $id ][ $bot ] = $value;
                }
            }
        }
        return $out;
    }

    /** Returns inherit, allow or block, never an untrusted arbitrary string. */
    public static function choice( array $policies, string $id, string $bot ): string {
        if ( ! isset( self::for_platform( $id )[ $bot ] ) ) { return 'inherit'; }
        $value = $policies[ $id ][ $bot ] ?? '';
        return 'allow' === $value || 'block' === $value ? $value : 'inherit';
    }
}
