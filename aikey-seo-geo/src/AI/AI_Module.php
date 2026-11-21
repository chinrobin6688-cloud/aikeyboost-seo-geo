<?php
namespace AIKEY\SeoGeo\AI;

use AIKEY\SeoGeo\Core\Module_Interface;
use AIKEY\SeoGeo\GEO\Scorer;
use AIKEY\SeoGeo\Integrations\Engine_Registry;
use AIKEY\SeoGeo\SEO\Audit\Audit_Module;
use AIKEY\SeoGeo\SEO\Meta\Meta_Repository;

defined( 'ABSPATH' ) || exit;

final class AI_Module implements Module_Interface {
    public function id(): string { return 'ai'; }

    public function register(): void { add_action( 'rest_api_init', array( $this, 'routes' ) ); }

    public function routes(): void {
        register_rest_route( 'aikey-seo-geo/v1', '/ai/analyze/(?P<id>\d+)', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array( $this, 'analyze' ),
            'permission_callback' => static fn( \WP_REST_Request $request ): bool => current_user_can( 'edit_post', (int) $request['id'] ),
            'args' => array( 'id'=>array( 'validate_callback'=>static fn( $v ): bool => is_numeric( $v ) && (int) $v > 0 ) ),
        ) );
        register_rest_route( 'aikey-seo-geo/v1', '/ai/optimize/(?P<id>\d+)', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array( $this, 'optimize' ),
            'permission_callback' => static fn( \WP_REST_Request $request ): bool => current_user_can( 'edit_post', (int) $request['id'] ),
            'args' => array( 'id'=>array( 'validate_callback'=>static fn( $v ): bool => is_numeric( $v ) && (int) $v > 0 ) ),
        ) );
        register_rest_route( 'aikey-seo-geo/v1', '/ai/content-tools/(?P<id>\d+)', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array( $this, 'content_tools' ),
            'permission_callback' => static fn( \WP_REST_Request $request ): bool => current_user_can( 'edit_post', (int) $request['id'] ),
            'args' => array( 'id'=>array( 'validate_callback'=>static fn( $v ): bool => is_numeric( $v ) && (int) $v > 0 ) ),
        ) );
    }

    public function analyze( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $post = get_post( (int) $request['id'] );
        if ( ! $post instanceof \WP_Post ) { return new \WP_Error( 'not_found', '页面不存在。', array( 'status'=>404 ) ); }
        $seo = Audit_Module::analyze( $post );
        $geo = Scorer::score_post( $post );
        $content = $this->plain_content( (string) $post->post_content, 12000 );
        $search_targets = implode( '、', Engine_Registry::search_labels() );
        $targets = implode( '、', Engine_Registry::ai_labels() );
        $system = '你是面向中国大陆企业网站的 SEO 与 GEO 内容审计专家。只依据页面中真实存在的信息分析，不虚构事实，不声称掌握任何平台未公开的排名算法。重点检查实体清晰度、事实密度、可验证证据、问答覆盖、内容结构、中文语义表达、移动端可读性和可直接引用段落。输出严格 JSON，不要 Markdown。JSON 字段：summary(string), strengths(array), problems(array), priority_actions(array), suggested_title(string), suggested_description(string), missing_questions(array), citable_passages(array), entity_advice(array), china_search_advice(array), ai_engine_readiness(array)。';
        $user = "网站：" . home_url( '/' ) . "\n页面标题：" . get_the_title( $post ) . "\n页面URL：" . get_permalink( $post ) . "\n核心关键词：" . Meta_Repository::focus_keyword( $post->ID ) . "\n目标搜索引擎：" . $search_targets . "\n目标AI引擎：" . $targets . "\n本地SEO评分：" . $seo['score'] . "/100\n本地GEO评分：" . $geo['score'] . "/100\n正文：\n" . $content;
        $result = ( new DeepSeek_Client() )->analyze( $system, $user );
        if ( is_wp_error( $result ) ) { return $result; }
        update_post_meta( $post->ID, Meta_Repository::AI_ANALYSIS, $result );
        return rest_ensure_response( array( 'ok'=>true, 'analysis'=>$result ) );
    }

    /**
     * Generates editable suggestions from the CURRENT block-editor content.
     * It never rewrites the post automatically; the user explicitly chooses
     * which suggestions to apply in the sidebar.
     */
    public function optimize( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $post = get_post( (int) $request['id'] );
        if ( ! $post instanceof \WP_Post ) { return new \WP_Error( 'not_found', '页面不存在。', array( 'status'=>404 ) ); }
        $payload = $request->get_json_params();
        $title = sanitize_text_field( (string) ( $payload['title'] ?? get_the_title( $post ) ) );
        $content_html = (string) ( $payload['content'] ?? $post->post_content );
        $content = $this->plain_content( $content_html, 16000 );
        $meta = isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : array();
        $keyword = sanitize_text_field( (string) ( $meta[ Meta_Repository::FOCUS_KEYWORD ] ?? Meta_Repository::focus_keyword( $post->ID ) ) );
        $entity = sanitize_text_field( (string) ( $meta[ Meta_Repository::GEO_ENTITY ] ?? Meta_Repository::get( $post->ID, Meta_Repository::GEO_ENTITY ) ) );

        $system = '你是中国大陆企业网站 SEO+GEO 编辑助手。你的任务是基于用户已经提供的正文生成可选择应用的结构化建议。禁止虚构企业资质、价格、参数、案例、客户、荣誉、检测数据、来源 URL 或任何正文中没有的信息。FAQ 的答案必须能从正文直接得到；如果正文没有答案，就不要生成该 FAQ。核心事实必须是正文中已经明确存在、可核对的事实。不要重写整篇正文。输出严格 JSON，不要 Markdown。字段必须为：suggested_title(string，建议20-70字符), suggested_description(string，建议60-180字符), focus_keyword(string), schema_type(string，仅允许 WebPage/Article/Product/Service/AboutPage/ContactPage), entity(string), entity_type(string，仅允许 Organization/Product/Service/Person/Place/Article), facts(array of string), faq(array，每项包含 q 和 a), missing_questions(array of string), optimization_notes(array of string)。';
        $user = "网站：" . home_url( '/' ) . "\n文章类型：" . $post->post_type . "\n当前标题：" . $title . "\n当前核心关键词：" . $keyword . "\n当前核心实体：" . $entity . "\n正文：\n" . $content;
        $result = ( new DeepSeek_Client() )->analyze( $system, $user, array( 'thinking'=>'disabled', 'max_tokens'=>4096 ) );
        if ( is_wp_error( $result ) ) { return $result; }
        $result = $this->sanitize_suggestions( $result );
        return rest_ensure_response( array( 'ok'=>true, 'suggestions'=>$result ) );
    }

    /**
     * Generates WordPress-native tags/excerpt and an H2/H3 conversion plan.
     * For headings, AI may ONLY select existing paragraph/heading blocks and assign a level.
     * It never creates or rewrites heading text.
     */
    public function content_tools( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $post = get_post( (int) $request['id'] );
        if ( ! $post instanceof \WP_Post ) { return new \WP_Error( 'not_found', '页面不存在。', array( 'status'=>404 ) ); }

        $payload = $request->get_json_params();
        $title = sanitize_text_field( (string) ( $payload['title'] ?? get_the_title( $post ) ) );
        $content_html = (string) ( $payload['content'] ?? $post->post_content );
        $content = $this->plain_content( $content_html, 14000 );
        $current_excerpt = sanitize_textarea_field( (string) ( $payload['excerpt'] ?? $post->post_excerpt ) );
        $scope = sanitize_key( (string) ( $payload['scope'] ?? 'all' ) );
        if ( ! in_array( $scope, array( 'tags','excerpt','headings','all' ), true ) ) { $scope = 'all'; }

        $units = array();
        $heading_units = array();
        foreach ( array_slice( (array) ( $payload['units'] ?? array() ), 0, 80 ) as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $id = sanitize_key( (string) ( $row['id'] ?? '' ) );
            if ( ! preg_match( '/^u\d+$/', $id ) ) { continue; }
            $type = sanitize_key( str_replace( '/', '-', (string) ( $row['type'] ?? '' ) ) );
            $level = in_array( (int) ( $row['level'] ?? 0 ), array( 2, 3, 4, 5, 6 ), true ) ? (int) $row['level'] : 0;
            $text = sanitize_text_field( (string) ( $row['text'] ?? '' ) );
            if ( '' === $text ) { continue; }
            $text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 320, 'UTF-8' ) : substr( $text, 0, 320 );
            $unit = array( 'id'=>$id, 'type'=>$type, 'level'=>$level, 'text'=>$text, 'order'=>count( $units ) );
            $units[] = $unit;

            // Only normal paragraphs and existing headings can ever be converted/re-leveled.
            // Long body paragraphs are intentionally excluded to reduce accidental conversions.
            $text_length = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
            if ( 'core-heading' === $type || ( 'core-paragraph' === $type && $text_length <= 80 ) ) {
                $heading_units[ $id ] = $unit;
            }
        }

        $system = '你是中国大陆企业网站的 WordPress 内容结构助手。只依据用户当前正文生成建议，禁止虚构正文没有的事实。当前操作 scope=' . $scope . '。当 scope=tags 时重点生成标签，其余字段可为空；scope=excerpt 时重点生成摘要；scope=headings 时只判断现有内容单元哪些本来就是小标题并设置 H2/H3；scope=all 时生成全部。标签要求 3-6 个，必须是正文真实主题或实体，简洁、互不重复，避免把同义词堆成多个标签，也不要生成“行业资讯”“最新消息”这类空泛词。摘要要求约 80-180 个中文字符，准确概括正文核心信息，不加入正文没有的承诺、数据或结论。H2/H3 规则非常严格：文章标题本身视为 H1；绝对禁止新增标题文字，绝对禁止改写、缩写或概括原文来创造标题；只能从用户提供的内容单元中选择“原本就承担章节标题作用”的 core-paragraph 或已有 core-heading，并给它指定 level=2 或 3。普通正文段落即使可以概括成标题也不要选择。H2 表示主要章节，H3 仅作为前面某个 H2 下的子主题；已有合理 heading 可以保持或调整层级。heading_levels 每项必须含 unit_id、level、original_text，其中 original_text 必须与该 unit 的 text 原样一致。若原文没有明确的小标题式段落，heading_levels 返回空数组，不要为了凑数量处理正文。输出严格 JSON，不要 Markdown。字段必须为 excerpt(string), tags(array of string), heading_levels(array，每项含 unit_id、level、original_text), notes(array of string)。JSON 示例：{"excerpt":"本文概述……","tags":["主题A","主题B"],"heading_levels":[{"unit_id":"u3","level":2,"original_text":"配方研发与打样"},{"unit_id":"u7","level":3,"original_text":"配方确认"}],"notes":[]}。';

        $units_json = wp_json_encode( $units, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $user = "网站：" . home_url( '/' ) . "\n文章类型：" . $post->post_type . "\n当前操作：" . $scope . "\n当前标题：" . $title . "\n当前摘要：" . $current_excerpt . "\n正文纯文本：\n" . $content . "\n\n内容单元（heading_levels 只能选择其中符合规则的现有单元）：\n" . ( $units_json ?: '[]' );
        $result = ( new DeepSeek_Client() )->analyze( $system, $user, array( 'thinking'=>'disabled', 'max_tokens'=>4096 ) );
        if ( is_wp_error( $result ) ) { return $result; }
        $result = $this->sanitize_content_tools( $result, $heading_units );
        return rest_ensure_response( array( 'ok'=>true, 'suggestions'=>$result ) );
    }

    /** @param array<string,array<string,mixed>> $heading_units */
    private function sanitize_content_tools( array $data, array $heading_units ): array {
        $excerpt = sanitize_textarea_field( (string) ( $data['excerpt'] ?? '' ) );
        if ( function_exists( 'mb_substr' ) ) { $excerpt = mb_substr( $excerpt, 0, 220, 'UTF-8' ); }
        else { $excerpt = substr( $excerpt, 0, 440 ); }

        $tags = array();
        $seen_tags = array();
        foreach ( array_slice( (array) ( $data['tags'] ?? array() ), 0, 8 ) as $tag ) {
            $tag = trim( sanitize_text_field( (string) $tag ) );
            if ( '' === $tag ) { continue; }
            $tag = function_exists( 'mb_substr' ) ? mb_substr( $tag, 0, 24, 'UTF-8' ) : substr( $tag, 0, 48 );
            $key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $tag, 'UTF-8' ) : strtolower( $tag );
            if ( isset( $seen_tags[ $key ] ) ) { continue; }
            $seen_tags[ $key ] = true;
            $tags[] = $tag;
            if ( count( $tags ) >= 6 ) { break; }
        }

        $heading_levels = array();
        $seen_units = array();
        foreach ( array_slice( (array) ( $data['heading_levels'] ?? array() ), 0, 12 ) as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $unit_id = sanitize_key( (string) ( $row['unit_id'] ?? '' ) );
            if ( isset( $seen_units[ $unit_id ] ) || ! isset( $heading_units[ $unit_id ] ) ) { continue; }
            $source = $heading_units[ $unit_id ];
            $original_text = trim( sanitize_text_field( (string) ( $row['original_text'] ?? '' ) ) );
            $source_text = trim( sanitize_text_field( (string) ( $source['text'] ?? '' ) ) );
            // AI must point back to exactly the existing text; never accept generated replacement copy.
            if ( '' === $source_text || '' === $original_text || $original_text !== $source_text ) { continue; }
            $level = 3 === (int) ( $row['level'] ?? 2 ) ? 3 : 2;
            $heading_levels[] = array(
                'unit_id'       => $unit_id,
                'level'         => $level,
                'original_text' => $source_text,
                'order'         => (int) ( $source['order'] ?? 0 ),
            );
            $seen_units[ $unit_id ] = true;
        }

        usort( $heading_levels, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );

        // Prevent orphan H3: an H3 must have an H2 before it, either already present
        // and not being demoted by this plan, or selected earlier in the plan.
        $planned_by_unit = array();
        foreach ( $heading_levels as $row ) { $planned_by_unit[ $row['unit_id'] ] = (int) $row['level']; }
        $h2_orders = array();
        foreach ( $heading_units as $unit_id => $source ) {
            if ( 'core-heading' !== (string) ( $source['type'] ?? '' ) || 2 !== (int) ( $source['level'] ?? 0 ) ) { continue; }
            if ( isset( $planned_by_unit[ $unit_id ] ) && 2 !== $planned_by_unit[ $unit_id ] ) { continue; }
            $h2_orders[] = (int) ( $source['order'] ?? 0 );
        }
        sort( $h2_orders );
        foreach ( $heading_levels as $i => $row ) {
            $has_h2_before = false;
            foreach ( $h2_orders as $h2_order ) {
                if ( $h2_order < $row['order'] ) { $has_h2_before = true; break; }
            }
            if ( 3 === $row['level'] && ! $has_h2_before ) { $heading_levels[ $i ]['level'] = 2; }
            if ( 2 === $heading_levels[ $i ]['level'] ) { $h2_orders[] = $row['order']; sort( $h2_orders ); }
            unset( $heading_levels[ $i ]['order'] );
        }

        $notes = array();
        foreach ( array_slice( (array) ( $data['notes'] ?? array() ), 0, 6 ) as $note ) {
            $note = trim( sanitize_text_field( (string) $note ) );
            if ( '' !== $note ) { $notes[] = $note; }
        }

        return array( 'excerpt'=>$excerpt, 'tags'=>$tags, 'heading_levels'=>$heading_levels, 'notes'=>$notes );
    }

    private function sanitize_suggestions( array $data ): array {
        $allowed_schema = array( 'WebPage','Article','Product','Service','AboutPage','ContactPage' );
        $allowed_entity = array( 'Organization','Product','Service','Person','Place','Article' );
        $out = array(
            'suggested_title' => sanitize_text_field( (string) ( $data['suggested_title'] ?? '' ) ),
            'suggested_description' => sanitize_textarea_field( (string) ( $data['suggested_description'] ?? '' ) ),
            'focus_keyword' => sanitize_text_field( (string) ( $data['focus_keyword'] ?? '' ) ),
            'schema_type' => in_array( (string) ( $data['schema_type'] ?? '' ), $allowed_schema, true ) ? (string) $data['schema_type'] : 'WebPage',
            'entity' => sanitize_text_field( (string) ( $data['entity'] ?? '' ) ),
            'entity_type' => in_array( (string) ( $data['entity_type'] ?? '' ), $allowed_entity, true ) ? (string) $data['entity_type'] : '',
            'facts' => array(), 'faq'=>array(), 'missing_questions'=>array(), 'optimization_notes'=>array(),
        );
        foreach ( array_slice( (array) ( $data['facts'] ?? array() ), 0, 12 ) as $fact ) {
            $fact = sanitize_text_field( (string) $fact ); if ( '' !== $fact ) { $out['facts'][] = $fact; }
        }
        foreach ( array_slice( (array) ( $data['faq'] ?? array() ), 0, 10 ) as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $q = sanitize_text_field( (string) ( $row['q'] ?? '' ) );
            $a = sanitize_textarea_field( (string) ( $row['a'] ?? '' ) );
            if ( '' !== $q && '' !== $a ) { $out['faq'][] = array( 'q'=>$q, 'a'=>$a ); }
        }
        foreach ( array( 'missing_questions','optimization_notes' ) as $field ) {
            foreach ( array_slice( (array) ( $data[ $field ] ?? array() ), 0, 12 ) as $item ) {
                $item = sanitize_text_field( (string) $item ); if ( '' !== $item ) { $out[ $field ][] = $item; }
            }
        }
        return $out;
    }

    private function plain_content( string $html, int $limit ): string {
        $text = wp_strip_all_tags( strip_shortcodes( $html ) );
        $text = preg_replace( '/\s+/u', ' ', $text ) ?: '';
        return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit, 'UTF-8' ) : substr( $text, 0, $limit );
    }
}
