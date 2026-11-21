<?php
namespace AIKEY\SeoGeo\SEO\Audit;

use AIKEY\SeoGeo\Core\Module_Interface;
use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\SEO\Meta\Meta_Repository;
use AIKEY\SeoGeo\Support\Utils;

defined( 'ABSPATH' ) || exit;

final class Audit_Module implements Module_Interface {
    public function id(): string { return 'seo_audit'; }
    public function register(): void { add_action( 'save_post', array( $this, 'on_save' ), 80, 2 ); }

    public function on_save( int $post_id, \WP_Post $post ): void {
        if ( Settings::module_enabled( 'automation' ) ) { return; }
        if ( wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status || ! in_array( $post->post_type, Utils::public_post_types(), true ) ) { return; }
        self::persist( $post );
    }

    public static function persist( \WP_Post $post ): array {
        $result = self::analyze( $post );
        update_post_meta( $post->ID, Meta_Repository::SEO_SCORE, (string) $result['score'] );
        update_post_meta( $post->ID, Meta_Repository::SEO_ISSUES, $result['issues'] );
        return $result;
    }

    public static function analyze( \WP_Post $post ): array {
        $score = 0;
        $issues = array();
        $title = Meta_Repository::preview_title( $post );
        $desc = Meta_Repository::effective_description( $post );
        $content = (string) $post->post_content;
        $plain = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $content ) ) ) ?: '' );
        $keyword = trim( Meta_Repository::focus_keyword( $post->ID ) );
        $title_len = Utils::text_length( $title );
        $desc_len = Utils::text_length( $desc );
        $plain_len = Utils::text_length( $plain );

        if ( $title_len >= 20 && $title_len <= 70 ) { $score += 15; } elseif ( $title_len > 0 ) { $score += 8; $issues[] = 'SEO 标题长度建议控制在约 20–70 个字符。'; } else { $issues[] = '缺少 SEO 标题。'; }
        if ( $desc_len >= 60 && $desc_len <= 180 ) { $score += 15; } elseif ( $desc_len > 0 ) { $score += 8; $issues[] = 'Meta 描述建议约 60–180 个字符，并准确概括页面。'; } else { $issues[] = '缺少 Meta 描述。'; }
        if ( $plain_len >= 500 ) { $score += 15; } elseif ( $plain_len >= 200 ) { $score += 9; $issues[] = '正文信息量偏少，建议补充有独立价值的内容。'; } else { $issues[] = '正文过短，搜索引擎难以充分理解页面主题。'; }
        if ( preg_match( '/<h[23][^>]*>/i', $content ) ) { $score += 10; } else { $issues[] = '正文缺少 H2/H3 层级结构。'; }

        if ( '' === $keyword ) { $score += 4; $issues[] = '未设置核心关键词；该字段仅用于分析，不会输出 meta keywords。'; }
        else {
            $haystack = $title . ' ' . $plain;
            if ( ( function_exists( 'mb_stripos' ) ? false !== mb_stripos( $haystack, $keyword, 0, 'UTF-8' ) : false !== stripos( $haystack, $keyword ) ) ) { $score += 10; } else { $score += 4; $issues[] = '核心关键词与标题/正文主题匹配度不足。'; }
        }

        preg_match_all( '/<img\b[^>]*>/i', $content, $images );
        if ( empty( $images[0] ) ) { $score += 10; }
        else {
            $with_alt = 0;
            foreach ( $images[0] as $img ) { if ( preg_match( '/\balt\s*=\s*(["\'])[^"\']+\1/i', $img ) ) { $with_alt++; } }
            $ratio = $with_alt / count( $images[0] );
            $score += (int) round( 10 * $ratio );
            if ( $ratio < 1 ) { $issues[] = '部分正文图片缺少有效 ALT 文本。'; }
        }

        preg_match_all( '/<a\s[^>]*href\s*=\s*(["\'])(.*?)\1/i', $content, $links );
        $internal = 0; $external = 0; $host = wp_parse_url( home_url(), PHP_URL_HOST );
        foreach ( $links[2] ?? array() as $href ) {
            $href_host = wp_parse_url( $href, PHP_URL_HOST );
            if ( ! $href_host || $href_host === $host ) { $internal++; } else { $external++; }
        }
        if ( $internal > 0 ) { $score += 6; } else { $issues[] = '建议增加至少一个有上下文意义的站内链接。'; }
        if ( $external > 0 ) { $score += 4; } else { $issues[] = '需要引用外部事实时，可增加权威来源链接。'; }

        if ( 'noindex' !== Meta_Repository::robots_index( $post->ID ) && (bool) get_option( 'blog_public', true ) ) { $score += 10; } else { $issues[] = '页面当前可能被设置为 noindex 或全站禁止索引。'; }
        $schema = Meta_Repository::schema_type( $post->ID );
        if ( '' !== $schema || 'post' === $post->post_type || ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) ) { $score += 5; } else { $score += 2; $issues[] = '可根据页面类型明确设置 Schema。'; }

        return array( 'score'=>min( 100, $score ), 'issues'=>array_values( array_unique( $issues ) ) );
    }

    public static function scan( int $limit = 200 ): array {
        $query = new \WP_Query( array( 'post_type'=>Utils::public_post_types(), 'post_status'=>'publish', 'posts_per_page'=>min( 500, max( 1, $limit ) ), 'orderby'=>'modified', 'order'=>'DESC' ) );
        $rows = array();
        foreach ( $query->posts as $post ) {
            $result = self::persist( $post );
            $rows[] = array( 'id'=>$post->ID, 'title'=>get_the_title( $post ), 'type'=>$post->post_type, 'score'=>$result['score'], 'issues'=>$result['issues'], 'url'=>get_permalink( $post ), 'edit'=>get_edit_post_link( $post->ID, '' ) );
        }
        return $rows;
    }
}
