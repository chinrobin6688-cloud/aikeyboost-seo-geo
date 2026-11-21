<?php
namespace AIKEY\SeoGeo\GEO;

use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\SEO\Meta\Meta_Repository;
use AIKEY\SeoGeo\Support\Utils;

defined( 'ABSPATH' ) || exit;

final class Scorer {
    public static function persist( \WP_Post $post ): array {
        $result = self::score_post( $post );
        update_post_meta( $post->ID, Meta_Repository::GEO_SCORE, (string) $result['score'] );
        update_post_meta( $post->ID, Meta_Repository::GEO_BREAKDOWN, $result['breakdown'] );
        return $result;
    }

    public static function score_post( \WP_Post $post ): array {
        $issues = array();
        $content = (string) $post->post_content;
        $plain = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $content ) ) ) ?: '' );
        $entity = trim( Meta_Repository::get( $post->ID, Meta_Repository::GEO_ENTITY ) );
        $entity_type = trim( Meta_Repository::get( $post->ID, Meta_Repository::GEO_ENTITY_TYPE ) );
        $facts = Utils::clean_lines( Meta_Repository::get( $post->ID, Meta_Repository::GEO_FACTS ), 100 );
        $faq = self::faq_pairs( Meta_Repository::get( $post->ID, Meta_Repository::GEO_FAQ ) );
        $sources = array_filter( array_map( static fn( string $v ): string => esc_url_raw( $v, array( 'http','https' ) ), Utils::clean_lines( Meta_Repository::get( $post->ID, Meta_Repository::GEO_SOURCES ), 50 ) ) );
        $schema = Settings::section( 'schema' );

        $technical = 0;
        if ( 'publish' === $post->post_status ) { $technical += 5; } else { $issues[] = '页面尚未公开发布。'; }
        if ( (bool) get_option( 'blog_public', true ) && 'noindex' !== Meta_Repository::robots_index( $post->ID ) ) { $technical += 5; } else { $issues[] = '页面可能不可被搜索/AI检索系统索引。'; }
        $url = (string) get_permalink( $post );
        if ( 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ) { $technical += 4; } else { $issues[] = '建议全站使用 HTTPS。'; }
        if ( '' !== Meta_Repository::effective_description( $post ) ) { $technical += 3; } else { $issues[] = '缺少清晰的页面摘要/Meta 描述。'; }
        $canonical = Meta_Repository::canonical( $post->ID );
        if ( '' === $canonical || wp_http_validate_url( $canonical ) ) { $technical += 3; } else { $issues[] = 'Canonical URL 格式异常。'; }

        $entity_score = 0;
        if ( '' !== $entity ) { $entity_score += 5; } else { $issues[] = '未明确页面核心实体名称。'; }
        if ( '' !== $entity_type || '' !== Meta_Repository::schema_type( $post->ID ) ) { $entity_score += 5; } else { $issues[] = '未明确实体/Schema 类型。'; }
        if ( '' !== trim( (string) ( $schema['organization_name'] ?? '' ) ) ) { $entity_score += 5; } else { $issues[] = '全站企业实体名称尚未配置。'; }
        if ( '' !== Meta_Repository::focus_keyword( $post->ID ) ) { $entity_score += 5; } else { $issues[] = '建议设置一个页面核心主题词用于内部分析。'; }

        $citable = 0;
        if ( 'publish' === $post->post_status && $facts && count( Public_Content::visible_facts( $post ) ) < count( $facts ) ) {
            $issues[] = '部分核心事实仅保存在后台：请核实正文内容，或按需放置 [aikey_geo_facts]。';
        }
        if ( 'publish' === $post->post_status && $faq && count( Public_Content::visible_faq( $post ) ) < count( $faq ) ) {
            $issues[] = '部分FAQ仅保存在后台：请核实正文可见问答，或按需放置 [aikey_geo_faq]。';
        }
        if ( count( $facts ) >= 3 ) { $citable += 8; } elseif ( count( $facts ) > 0 ) { $citable += 4; $issues[] = '核心事实数量偏少，建议补充可验证的资质、参数、案例或数据。'; } else { $issues[] = '缺少可直接引用的核心事实。'; }
        if ( count( $faq ) >= 3 ) { $citable += 6; } elseif ( count( $faq ) > 0 ) { $citable += 3; $issues[] = 'FAQ 较少，可补充用户真实决策问题。'; } else { $issues[] = '缺少明确问答结构。'; }
        if ( preg_match( '/\d/u', $plain ) ) { $citable += 3; } else { $issues[] = '正文缺少具体数字、参数或事实信息。'; }
        if ( Utils::text_length( $plain ) >= 600 ) { $citable += 3; } else { $issues[] = '正文信息密度偏低，建议补充有独立价值的内容。'; }

        $trust = 0;
        if ( (int) $post->post_author > 0 ) { $trust += 3; }
        if ( $post->post_date_gmt && $post->post_modified_gmt ) { $trust += 3; }
        if ( count( $sources ) >= 2 ) { $trust += 6; } elseif ( 1 === count( $sources ) ) { $trust += 3; $issues[] = '证据来源较少。'; } else { $issues[] = '页面未配置公开可验证的来源/证据 URL。'; }
        if ( '' !== trim( (string) ( $schema['telephone'] ?? '' ) ) || '' !== trim( (string) ( $schema['email'] ?? '' ) ) || '' !== trim( (string) ( $schema['logo_url'] ?? '' ) ) ) { $trust += 3; } else { $issues[] = '企业联系方式/Logo 等信任信息未完善。'; }

        $structure = 0;
        if ( preg_match( '/<h[23][^>]*>/i', $content ) ) { $structure += 5; } else { $issues[] = '正文缺少 H2/H3 信息层级。'; }
        if ( preg_match( '/<(ul|ol)\b/i', $content ) ) { $structure += 3; }
        if ( preg_match( '/<table\b/i', $content ) ) { $structure += 3; }
        if ( substr_count( strtolower( $content ), '<p' ) >= 3 ) { $structure += 2; }
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( preg_match( '/href=["\'](?:' . preg_quote( home_url(), '/' ) . '|\/)/i', $content ) ) { $structure += 2; } else { $issues[] = '建议增加与主题相关的站内链接。'; }

        $consistency = 0;
        if ( count( Utils::clean_lines( (string) ( $schema['same_as'] ?? '' ), 20 ) ) >= 2 ) { $consistency += 4; } elseif ( '' !== trim( (string) ( $schema['same_as'] ?? '' ) ) ) { $consistency += 2; $issues[] = '外部实体链接数量较少。'; } else { $issues[] = '未配置企业外部实体链接（官网外的权威资料页/社媒等）。'; }
        if ( preg_match_all( '/<a\s[^>]*href=["\']https?:\/\/([^"\']+)/i', $content, $matches ) && ! empty( $matches[1] ) ) {
            foreach ( $matches[1] as $link_host ) { if ( $host && ! str_contains( $link_host, (string) $host ) ) { $consistency += 3; break; } }
        }
        if ( '' !== $entity && ( self::contains( get_the_title( $post ) . ' ' . $plain, $entity ) ) ) { $consistency += 3; } elseif ( '' !== $entity ) { $issues[] = '核心实体名称没有在标题/正文中清晰出现。'; }

        $breakdown = array(
            '技术可访问性' => min( 20, $technical ),
            '实体清晰度' => min( 20, $entity_score ),
            '内容可引用性' => min( 20, $citable ),
            '信任与证据' => min( 15, $trust ),
            '内容结构' => min( 15, $structure ),
            '实体一致性准备度' => min( 10, $consistency ),
        );
        return array( 'score'=>array_sum( $breakdown ), 'breakdown'=>$breakdown, 'issues'=>array_values( array_unique( $issues ) ) );
    }

    public static function faq_pairs( string $raw ): array {
        $pairs = array();
        foreach ( Utils::clean_lines( $raw, 50 ) as $line ) {
            if ( false === strpos( $line, '||' ) ) { continue; }
            [ $q, $a ] = array_map( 'trim', explode( '||', $line, 2 ) );
            if ( '' !== $q && '' !== $a ) { $pairs[] = array( 'q'=>$q, 'a'=>$a ); }
        }
        return $pairs;
    }

    private static function contains( string $haystack, string $needle ): bool {
        return function_exists( 'mb_stripos' ) ? false !== mb_stripos( $haystack, $needle, 0, 'UTF-8' ) : false !== stripos( $haystack, $needle );
    }
}
