<?php
namespace AIKEY\SeoGeo\Optimization;

use AIKEY\SeoGeo\AI\DeepSeek_Client;
use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\GEO\Scorer;
use AIKEY\SeoGeo\Integrations\Baidu_Submitter;
use AIKEY\SeoGeo\Integrations\Daily_Submission;
use AIKEY\SeoGeo\Integrations\IndexNow_Module;
use AIKEY\SeoGeo\SEO\Audit\Audit_Module;
use AIKEY\SeoGeo\SEO\Meta\Meta_Repository;
use AIKEY\SeoGeo\Support\Utils;

defined( 'ABSPATH' ) || exit;

final class Optimization_Workbench {
    private const TRANSIENT_PREFIX = 'aikey_workbench_ai_';
    private const SUBMIT_HOOK = 'aikey_seo_geo_workbench_submit';

    public static function register_hooks(): void {
        add_action( self::SUBMIT_HOOK, array( self::class, 'notify_posts' ), 10, 1 );
    }

    public static function analyze( \WP_Post $post ): array {
        return array( 'seo'=>Audit_Module::analyze( $post ), 'geo'=>Scorer::score_post( $post ) );
    }

    /**
     * Locate editable public content without scanning every permalink on the site.
     *
     * A same-site front-end URL or relative path is resolved first and returns the
     * exact post immediately. Otherwise WordPress performs a bounded title search;
     * an exact slug match is also included when available.
     *
     * @return array<int,\WP_Post>|\WP_Error
     */
    public static function search_public_posts( string $query, int $limit = 50 ): array|\WP_Error {
        $query = self::truncate( trim( sanitize_text_field( $query ) ), 500 );
        if ( '' === $query ) { return array(); }

        $types = Utils::public_post_types();
        if ( ! $types ) { return array(); }

        $limit = max( 1, min( 50, $limit ) );
        $results = array();
        $append = static function( $post ) use ( &$results, $types, $limit ): void {
            if ( count( $results ) >= $limit || ! $post instanceof \WP_Post ) { return; }
            if ( 'publish' !== $post->post_status || ! in_array( $post->post_type, $types, true ) ) { return; }
            if ( ! current_user_can( 'edit_post', $post->ID ) ) { return; }
            $results[ $post->ID ] = $post;
        };

        $site_url = self::site_url_from_search( $query );
        if ( is_wp_error( $site_url ) ) { return $site_url; }
        if ( '' !== $site_url ) {
            $front_id = absint( get_option( 'page_on_front', 0 ) );
            if ( $front_id > 0 && self::same_url( $site_url, home_url( '/' ) ) ) {
                $append( get_post( $front_id ) );
            } else {
                $resolved_id = absint( url_to_postid( $site_url ) );
                if ( $resolved_id > 0 ) { $append( get_post( $resolved_id ) ); }
            }
            // An exact URL should never fall through to a broad content search.
            if ( $results ) { return array_values( $results ); }

            $path = self::relative_site_path( $site_url );
            if ( '' !== $path ) {
                $path_match = get_page_by_path( $path, OBJECT, $types );
                if ( $path_match instanceof \WP_Post ) { $append( $path_match ); }
            }
            return array_values( $results );
        }

        if ( ctype_digit( $query ) ) { $append( get_post( absint( $query ) ) ); }

        $slug = sanitize_title( $query );
        if ( '' !== $slug ) {
            $slug_query = new \WP_Query( array(
                'post_type'              => $types,
                'post_status'            => 'publish',
                'name'                   => $slug,
                'posts_per_page'         => min( $limit, 10 ),
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
            ) );
            foreach ( (array) $slug_query->posts as $post ) { $append( $post ); }
        }

        if ( count( $results ) < $limit ) {
            $title_query = new \WP_Query( array(
                'post_type'              => $types,
                'post_status'            => 'publish',
                's'                      => $query,
                'search_columns'         => array( 'post_title' ),
                'posts_per_page'         => $limit,
                'orderby'                => 'relevance',
                'order'                  => 'DESC',
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
            ) );
            foreach ( (array) $title_query->posts as $post ) { $append( $post ); }
        }

        return array_slice( array_values( $results ), 0, $limit );
    }

    public static function snapshot( \WP_Post $post ): array {
        $tags = array();
        if ( is_object_in_taxonomy( $post->post_type, 'post_tag' ) ) {
            $terms = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields'=>'names' ) );
            if ( ! is_wp_error( $terms ) ) { $tags = array_values( array_map( 'strval', $terms ) ); }
        }
        return array(
            'seo_title'=>Meta_Repository::title( $post->ID ),
            'description'=>Meta_Repository::description( $post->ID ),
            'focus_keyword'=>Meta_Repository::focus_keyword( $post->ID ),
            'canonical'=>Meta_Repository::canonical( $post->ID ),
            'robots_index'=>Meta_Repository::robots_index( $post->ID ),
            'robots_follow'=>Meta_Repository::robots_follow( $post->ID ),
            'schema_type'=>Meta_Repository::schema_type( $post->ID ),
            'excerpt'=>(string) $post->post_excerpt,
            'tags'=>$tags,
            'entity'=>Meta_Repository::get( $post->ID, Meta_Repository::GEO_ENTITY ),
            'entity_type'=>Meta_Repository::get( $post->ID, Meta_Repository::GEO_ENTITY_TYPE ),
            'facts'=>Meta_Repository::get( $post->ID, Meta_Repository::GEO_FACTS ),
            'faq'=>Meta_Repository::get( $post->ID, Meta_Repository::GEO_FAQ ),
            'sources'=>Meta_Repository::get( $post->ID, Meta_Repository::GEO_SOURCES ),
            'content_hash'=>self::content_hash( (string) $post->post_content ),
        );
    }

    public static function safe_optimize( int $post_id, bool $notify = true ): array|\WP_Error {
        $post = self::editable_post( $post_id );
        if ( is_wp_error( $post ) ) { return $post; }
        $changed = array();
        $plain = self::plain_content( (string) $post->post_content, 6000 );
        $post_update = array( 'ID'=>$post_id );
        $needs_update = false;

        if ( '' === Meta_Repository::description( $post_id ) ) {
            $desc = self::description_from_text( '' !== trim( (string) $post->post_excerpt ) ? (string) $post->post_excerpt : $plain );
            if ( Utils::text_length( $desc ) >= 60 ) {
                update_post_meta( $post_id, Meta_Repository::DESCRIPTION, $desc );
                $changed[] = 'Meta 描述';
            }
        }
        if ( post_type_supports( $post->post_type, 'excerpt' ) && '' === trim( (string) $post->post_excerpt ) && Utils::text_length( $plain ) >= 80 ) {
            $post_update['post_excerpt'] = self::truncate( $plain, 180 );
            $needs_update = true;
            $changed[] = 'WordPress 摘要';
        }
        if ( '' === Meta_Repository::schema_type( $post_id ) ) {
            $schema = self::detect_schema_type( $post );
            if ( '' !== $schema ) { update_post_meta( $post_id, Meta_Repository::SCHEMA_TYPE, $schema ); $changed[] = 'Schema 类型'; }
        }
        if ( '' === Meta_Repository::get( $post_id, Meta_Repository::GEO_ENTITY ) ) {
            $keyword = trim( Meta_Repository::focus_keyword( $post_id ) );
            if ( '' !== $keyword ) { update_post_meta( $post_id, Meta_Repository::GEO_ENTITY, self::truncate( $keyword, 120 ) ); $changed[] = 'GEO 核心实体'; }
        }
        if ( '' === Meta_Repository::get( $post_id, Meta_Repository::GEO_ENTITY_TYPE ) ) {
            $schema = Meta_Repository::schema_type( $post_id );
            $map = array( 'Article'=>'Article','Product'=>'Product','Service'=>'Service','AboutPage'=>'Organization','ContactPage'=>'Organization' );
            if ( isset( $map[ $schema ] ) ) { update_post_meta( $post_id, Meta_Repository::GEO_ENTITY_TYPE, $map[ $schema ] ); $changed[] = 'GEO 实体类型'; }
        }
        if ( $needs_update ) {
            $result = wp_update_post( wp_slash( $post_update ), true );
            if ( is_wp_error( $result ) ) { return $result; }
        }
        $scores = self::rescore( $post_id );
        if ( $notify && $changed && ! $needs_update ) { self::queue_notify( array( $post_id ) ); }
        return array( 'changed'=>array_values( array_unique( $changed ) ), 'scores'=>$scores );
    }

    public static function save_manual( int $post_id, array $input ): array|\WP_Error {
        $post = self::editable_post( $post_id );
        if ( is_wp_error( $post ) ) { return $post; }
        $changed = array();

        $simple = array(
            Meta_Repository::TITLE=>array( 'wb_seo_title',300,'text','SEO 标题' ),
            Meta_Repository::DESCRIPTION=>array( 'wb_description',600,'textarea','Meta 描述' ),
            Meta_Repository::FOCUS_KEYWORD=>array( 'wb_focus_keyword',160,'text','核心关键词' ),
            Meta_Repository::GEO_ENTITY=>array( 'wb_entity',200,'text','GEO 核心实体' ),
            Meta_Repository::GEO_FACTS=>array( 'wb_facts',5000,'textarea','GEO 核心事实' ),
            Meta_Repository::GEO_FAQ=>array( 'wb_faq',8000,'textarea','GEO FAQ' ),
        );
        foreach ( $simple as $meta_key => $cfg ) {
            [ $field, $limit, $kind, $label ] = $cfg;
            $raw = isset( $input[ $field ] ) ? (string) $input[ $field ] : '';
            $value = 'textarea' === $kind ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
            self::update_meta_if_changed( $post_id, $meta_key, self::truncate( $value, $limit ), $label, $changed );
        }
        if ( array_key_exists( 'wb_focus_keyword', $input ) ) {
            Meta_Repository::sync_primary_keyword( $post_id, Meta_Repository::focus_keyword( $post_id ) );
        }

        $canonical = esc_url_raw( trim( (string) ( $input['wb_canonical'] ?? '' ) ), array( 'http','https' ) );
        self::update_meta_if_changed( $post_id, Meta_Repository::CANONICAL, $canonical, 'Canonical', $changed );

        $index = sanitize_key( (string) ( $input['wb_robots_index'] ?? 'default' ) );
        if ( ! in_array( $index, array( 'default','index','noindex' ), true ) ) { $index = 'default'; }
        self::update_meta_if_changed( $post_id, Meta_Repository::ROBOTS_INDEX, $index, 'Robots Index', $changed );
        $follow = sanitize_key( (string) ( $input['wb_robots_follow'] ?? 'default' ) );
        if ( ! in_array( $follow, array( 'default','follow','nofollow' ), true ) ) { $follow = 'default'; }
        self::update_meta_if_changed( $post_id, Meta_Repository::ROBOTS_FOLLOW, $follow, 'Robots Follow', $changed );

        $schema_allowed = array( '','WebPage','Article','Product','Service','AboutPage','ContactPage' );
        $schema = sanitize_text_field( (string) ( $input['wb_schema_type'] ?? '' ) );
        if ( ! in_array( $schema, $schema_allowed, true ) ) { $schema = ''; }
        self::update_meta_if_changed( $post_id, Meta_Repository::SCHEMA_TYPE, $schema, 'Schema 类型', $changed );

        $entity_allowed = array( '','Organization','Product','Service','Person','Place','Article' );
        $entity_type = sanitize_text_field( (string) ( $input['wb_entity_type'] ?? '' ) );
        if ( ! in_array( $entity_type, $entity_allowed, true ) ) { $entity_type = ''; }
        self::update_meta_if_changed( $post_id, Meta_Repository::GEO_ENTITY_TYPE, $entity_type, 'GEO 实体类型', $changed );

        self::update_meta_if_changed( $post_id, Meta_Repository::GEO_SOURCES, self::sanitize_source_lines( (string) ( $input['wb_sources'] ?? '' ) ), 'GEO 来源', $changed );

        $post_was_updated = false;
        if ( post_type_supports( $post->post_type, 'excerpt' ) ) {
            $excerpt = self::truncate( sanitize_textarea_field( (string) ( $input['wb_excerpt'] ?? '' ) ), 800 );
            if ( $excerpt !== (string) $post->post_excerpt ) {
                $result = wp_update_post( wp_slash( array( 'ID'=>$post_id, 'post_excerpt'=>$excerpt ) ), true );
                if ( is_wp_error( $result ) ) { return $result; }
                $changed[] = 'WordPress 摘要';
                $post_was_updated = true;
            }
        }
        if ( is_object_in_taxonomy( $post->post_type, 'post_tag' ) && array_key_exists( 'wb_tags', $input ) ) {
            $tags = self::sanitize_tags( (string) $input['wb_tags'] );
            $old = wp_get_post_terms( $post_id, 'post_tag', array( 'fields'=>'names' ) );
            $old = is_wp_error( $old ) ? array() : array_values( array_map( 'strval', $old ) );
            if ( $tags !== $old ) {
                $result = wp_set_post_terms( $post_id, $tags, 'post_tag', false );
                if ( is_wp_error( $result ) ) { return $result; }
                $changed[] = 'WordPress 标签';
            }
        }
        $scores = self::rescore( $post_id );
        if ( $changed && ! $post_was_updated ) { self::queue_notify( array( $post_id ) ); }
        return array( 'changed'=>array_values( array_unique( $changed ) ), 'scores'=>$scores );
    }

    public static function generate_ai( int $post_id, int $user_id ): array|\WP_Error {
        $post = self::editable_post( $post_id );
        if ( is_wp_error( $post ) ) { return $post; }
        if ( '' === Settings::api_key() ) { return new \WP_Error( 'ai_key_missing', '请先在“设置 → DeepSeek AI”中配置 API Key。' ); }
        $current = self::snapshot( $post );
        $units = self::heading_candidates( (string) $post->post_content );
        $supports_tags = is_object_in_taxonomy( $post->post_type, 'post_tag' );
        $supports_excerpt = post_type_supports( $post->post_type, 'excerpt' );
        $system = '你是中国大陆企业网站的 SEO+GEO 优化助手。只依据用户提供的当前页面内容生成可选择应用的建议，不得虚构正文没有的资质、客户、案例、参数、价格、检测数据、荣誉、来源 URL。不要重写整篇正文。SEO标题建议约20-70字符，Meta描述建议60-180字符。FAQ答案必须能从正文直接得到。核心事实必须是正文已明确存在、可核对的事实。标签必须是正文真实主题或实体，3-6个。H2/H3只能从 heading_candidates 中选择现有段落/标题，禁止新增、改写或概括标题文字；original_text 必须与候选 text 完全一致。输出严格JSON，不要Markdown。字段：seo_title, description, focus_keyword, schema_type(仅WebPage/Article/Product/Service/AboutPage/ContactPage), excerpt, tags(array), entity, entity_type(仅Organization/Product/Service/Person/Place/Article), facts(array), faq(array，每项q和a), heading_levels(array，每项unit_id、level(2或3)、original_text), notes(array)。';
        $user = "网站：" . home_url( '/' ) . "\n页面类型：" . $post->post_type . "\n页面标题：" . get_the_title( $post ) . "\n页面URL：" . get_permalink( $post ) . "\n当前SEO标题：" . (string) $current['seo_title'] . "\n当前Meta描述：" . (string) $current['description'] . "\n当前核心关键词：" . (string) $current['focus_keyword'] . "\n当前实体：" . (string) $current['entity'] . "\n支持摘要：" . ( $supports_excerpt ? '是' : '否' ) . "\n支持标签：" . ( $supports_tags ? '是' : '否' ) . "\n正文：\n" . self::plain_content( (string) $post->post_content, 16000 ) . "\n\nheading_candidates：\n" . ( wp_json_encode( array_values( $units ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ?: '[]' );
        $result = ( new DeepSeek_Client() )->analyze( $system, $user, array( 'thinking'=>'disabled', 'max_tokens'=>6144 ) );
        if ( is_wp_error( $result ) ) { return $result; }
        $bundle = array(
            'post_id'=>$post_id,
            'generated_at'=>time(),
            'content_hash'=>self::content_hash( (string) $post->post_content ),
            'suggestions'=>self::sanitize_ai_result( $result, $units, $supports_excerpt, $supports_tags ),
        );
        set_transient( self::transient_key( $user_id, $post_id ), $bundle, 2 * HOUR_IN_SECONDS );
        return $bundle;
    }

    public static function ai_bundle( int $post_id, int $user_id ): ?array {
        $value = get_transient( self::transient_key( $user_id, $post_id ) );
        return is_array( $value ) ? $value : null;
    }

    public static function apply_ai( int $post_id, int $user_id, array $fields ): array|\WP_Error {
        $post = self::editable_post( $post_id );
        if ( is_wp_error( $post ) ) { return $post; }
        $bundle = self::ai_bundle( $post_id, $user_id );
        if ( ! is_array( $bundle ) || empty( $bundle['suggestions'] ) || ! is_array( $bundle['suggestions'] ) ) { return new \WP_Error( 'ai_suggestion_expired', 'AI 建议已过期，请重新生成。' ); }
        $allowed = array( 'seo_title','description','focus_keyword','schema_type','excerpt','tags','entity','entity_type','facts','faq','headings' );
        $fields = array_values( array_intersect( $allowed, array_map( 'sanitize_key', $fields ) ) );
        if ( ! $fields ) { return new \WP_Error( 'nothing_selected', '请至少勾选一项 AI 建议。' ); }
        if ( ! hash_equals( (string) ( $bundle['content_hash'] ?? '' ), self::content_hash( (string) $post->post_content ) ) ) {
            return new \WP_Error( 'content_changed', '生成 AI 建议后正文已经发生变化。为避免应用过期建议，请重新生成 AI 优化建议。' );
        }
        $s = $bundle['suggestions'];
        $changed = array();
        $meta_map = array(
            'seo_title'=>array( Meta_Repository::TITLE,'SEO 标题' ),
            'description'=>array( Meta_Repository::DESCRIPTION,'Meta 描述' ),
            'focus_keyword'=>array( Meta_Repository::FOCUS_KEYWORD,'核心关键词' ),
            'schema_type'=>array( Meta_Repository::SCHEMA_TYPE,'Schema 类型' ),
            'entity'=>array( Meta_Repository::GEO_ENTITY,'GEO 核心实体' ),
            'entity_type'=>array( Meta_Repository::GEO_ENTITY_TYPE,'GEO 实体类型' ),
        );
        foreach ( $meta_map as $field=>$cfg ) {
            if ( in_array( $field, $fields, true ) && isset( $s[ $field ] ) ) { self::update_meta_if_changed( $post_id, $cfg[0], (string) $s[ $field ], $cfg[1], $changed ); }
        }
        if ( in_array( 'focus_keyword', $fields, true ) && isset( $s['focus_keyword'] ) ) {
            Meta_Repository::sync_primary_keyword( $post_id, Meta_Repository::focus_keyword( $post_id ) );
        }
        if ( in_array( 'facts', $fields, true ) ) { self::update_meta_if_changed( $post_id, Meta_Repository::GEO_FACTS, implode( "\n", array_map( 'strval', (array) ( $s['facts'] ?? array() ) ) ), 'GEO 核心事实', $changed ); }
        if ( in_array( 'faq', $fields, true ) ) {
            $lines = array(); foreach ( (array) ( $s['faq'] ?? array() ) as $row ) { if ( is_array( $row ) && isset( $row['q'],$row['a'] ) ) { $lines[] = trim( (string) $row['q'] ) . '||' . trim( (string) $row['a'] ); } }
            self::update_meta_if_changed( $post_id, Meta_Repository::GEO_FAQ, implode( "\n", $lines ), 'GEO FAQ', $changed );
        }
        $update = array( 'ID'=>$post_id ); $needs_update = false;
        if ( in_array( 'excerpt', $fields, true ) && post_type_supports( $post->post_type, 'excerpt' ) ) {
            $excerpt = (string) ( $s['excerpt'] ?? '' ); if ( $excerpt !== (string) $post->post_excerpt ) { $update['post_excerpt']=$excerpt; $needs_update=true; $changed[]='WordPress 摘要'; }
        }
        if ( in_array( 'headings', $fields, true ) && ! empty( $s['heading_levels'] ) ) {
            $new_content = self::apply_heading_plan( (string) $post->post_content, (array) $s['heading_levels'] );
            if ( is_wp_error( $new_content ) ) { return $new_content; }
            if ( $new_content !== (string) $post->post_content ) { $update['post_content']=$new_content; $needs_update=true; $changed[]='原文 H2/H3 层级'; }
        }
        if ( $needs_update ) { $result = wp_update_post( wp_slash( $update ), true ); if ( is_wp_error( $result ) ) { return $result; } }
        if ( in_array( 'tags', $fields, true ) && is_object_in_taxonomy( $post->post_type, 'post_tag' ) ) {
            $result = wp_set_post_terms( $post_id, array_values( array_map( 'strval', (array) ( $s['tags'] ?? array() ) ) ), 'post_tag', false ); if ( is_wp_error( $result ) ) { return $result; } $changed[]='WordPress 标签';
        }
        delete_transient( self::transient_key( $user_id, $post_id ) );
        $scores = self::rescore( $post_id );
        if ( $changed && ! $needs_update ) { self::queue_notify( array( $post_id ) ); }
        return array( 'changed'=>array_values( array_unique( $changed ) ), 'scores'=>$scores );
    }

    public static function rescore( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) { return array( 'seo'=>0,'geo'=>0 ); }
        $seo = Audit_Module::persist( $post ); $geo = Scorer::persist( $post );
        return array( 'seo'=>(int) $seo['score'], 'geo'=>(int) $geo['score'] );
    }

    public static function queue_notify( array $post_ids ): void {
        $ids = array_values( array_filter( array_unique( array_map( 'absint', $post_ids ) ) ) );
        if ( ! $ids ) { return; }
        Daily_Submission::mark_dirty( $ids );
        // Keep search-engine HTTP requests out of the admin save request. The
        // WordPress data is already saved synchronously; IndexNow/Baidu are
        // notified shortly afterward through WP-Cron.
        wp_schedule_single_event( time() + 5, self::SUBMIT_HOOK, array( $ids ) );
    }

    public static function notify_posts( array $post_ids ): void {
        $urls = array();
        $eligible_ids = array();
        foreach ( array_values( array_unique( array_map( 'absint', $post_ids ) ) ) as $post_id ) {
            $post = get_post( $post_id ); if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || 'noindex' === Meta_Repository::robots_index( $post_id ) ) { continue; }
            $url = get_permalink( $post ); if ( $url ) { $urls[] = $url; $eligible_ids[] = $post_id; }
        }
        if ( ! $urls ) { return; }
        if ( Settings::module_enabled( 'indexnow' ) && Settings::section_get( 'indexnow', 'auto_submit', true ) ) {
            $result = IndexNow_Module::submit_urls( $urls, 'workbench' );
            if ( ! is_wp_error( $result ) && (int) ( $result['success'] ?? 0 ) === count( $urls ) ) {
                Daily_Submission::mark_posts_success( $eligible_ids, IndexNow_Module::LAST_SIG );
            }
        }
        if ( Settings::module_enabled( 'baidu' ) && Settings::section_get( 'baidu', 'auto_submit_updates', false ) ) {
            $result = Baidu_Submitter::submit_urls( $urls, 'workbench' );
            if ( ! is_wp_error( $result ) && (int) ( $result['success'] ?? 0 ) === count( $urls ) ) {
                Daily_Submission::mark_posts_success( $eligible_ids, \AIKEY\SeoGeo\Integrations\Baidu_Module::LAST_SIG );
            }
        }
    }

    /**
     * Convert a search value to the current site's canonical URL without making
     * an HTTP request. Non-URL text returns an empty string for title searching.
     */
    private static function site_url_from_search( string $query ): string|\WP_Error {
        $raw = trim( $query );
        if ( '' === $raw ) { return ''; }

        $looks_like_path = str_starts_with( $raw, '/' );
        $looks_like_url = (bool) preg_match( '#^https?://#i', $raw );
        if ( ! $looks_like_url && ! $looks_like_path && preg_match( '/^[^\s\/]+\.[^\s\/]+\/.+$/u', $raw ) ) {
            $raw = 'https://' . $raw;
            $looks_like_url = true;
        }
        if ( ! $looks_like_url && ! $looks_like_path ) { return ''; }

        if ( $looks_like_path ) {
            $path = wp_parse_url( $raw, PHP_URL_PATH );
            $path = is_string( $path ) && '' !== $path ? $path : '/';
            return self::site_url_for_path( $path );
        }

        $scheme = strtolower( (string) wp_parse_url( $raw, PHP_URL_SCHEME ) );
        $host = strtolower( rtrim( (string) wp_parse_url( $raw, PHP_URL_HOST ), '.' ) );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
            return new \WP_Error( 'invalid_search_url', '请输入有效的本站前台网址。' );
        }
        $allowed_hosts = array_filter( array_unique( array_map(
            static fn( string $url ): string => strtolower( rtrim( (string) wp_parse_url( $url, PHP_URL_HOST ), '.' ) ),
            array( home_url( '/' ), site_url( '/' ) )
        ) ) );
        if ( ! in_array( $host, $allowed_hosts, true ) ) {
            return new \WP_Error( 'external_search_url', '只能通过当前网站的前台网址搜索页面。' );
        }

        $path = wp_parse_url( $raw, PHP_URL_PATH );
        $path = is_string( $path ) && '' !== $path ? $path : '/';
        return self::site_url_for_path( $path );
    }

    private static function site_url_for_path( string $path ): string {
        $home = home_url( '/' );
        $scheme = (string) wp_parse_url( $home, PHP_URL_SCHEME );
        $host = (string) wp_parse_url( $home, PHP_URL_HOST );
        $port = wp_parse_url( $home, PHP_URL_PORT );
        $home_path = (string) ( wp_parse_url( $home, PHP_URL_PATH ) ?: '/' );
        $home_path = '/' . trim( $home_path, '/' );
        if ( '/' !== $home_path && ( $path === $home_path || str_starts_with( $path, $home_path . '/' ) ) ) {
            $origin = $scheme . '://' . $host . ( $port ? ':' . (int) $port : '' );
            return $origin . $path;
        }
        return home_url( $path );
    }

    private static function relative_site_path( string $url ): string {
        $path = rawurldecode( (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: '' ) );
        $home_path = rawurldecode( (string) ( wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?: '/' ) );
        $home_path = '/' . trim( $home_path, '/' );
        if ( '/' !== $home_path && str_starts_with( $path, $home_path . '/' ) ) {
            $path = substr( $path, strlen( $home_path ) );
        } elseif ( $path === $home_path ) {
            $path = '/';
        }
        return trim( $path, '/' );
    }

    private static function same_url( string $left, string $right ): bool {
        return untrailingslashit( strtolower( $left ) ) === untrailingslashit( strtolower( $right ) );
    }

    private static function editable_post( int $post_id ): \WP_Post|\WP_Error {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, Utils::public_post_types(), true ) ) { return new \WP_Error( 'invalid_post', '找不到可优化的公开内容。' ); }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return new \WP_Error( 'forbidden', '你没有权限编辑该页面。' ); }
        return $post;
    }

    private static function update_meta_if_changed( int $post_id, string $key, string $value, string $label, array &$changed ): void {
        $old = get_post_meta( $post_id, $key, true ); $old = is_string( $old ) ? $old : '';
        if ( $old === $value ) { return; }
        if ( '' === $value ) { delete_post_meta( $post_id, $key ); } else { update_post_meta( $post_id, $key, $value ); }
        $changed[] = $label;
    }
    private static function description_from_text( string $text ): string { return self::truncate( trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $text ) ) ) ?: '' ), 160 ); }
    private static function plain_content( string $html, int $limit ): string { return self::truncate( trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $html ) ) ) ?: '' ), $limit ); }
    private static function truncate( string $text, int $length ): string { return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length, 'UTF-8' ) : substr( $text, 0, $length ); }
    private static function sanitize_tags( string $raw ): array {
        $items = preg_split( '/[,，\n\r]+/u', $raw ) ?: array(); $out = array();
        foreach ( array_slice( $items, 0, 20 ) as $item ) { $item = self::truncate( sanitize_text_field( trim( (string) $item ) ), 60 ); if ( '' !== $item && ! in_array( $item, $out, true ) ) { $out[]=$item; } }
        return $out;
    }
    private static function sanitize_source_lines( string $raw ): string {
        $out=array(); foreach ( Utils::clean_lines( sanitize_textarea_field( $raw ), 50 ) as $line ) { $url=esc_url_raw( trim( $line ), array( 'http','https' ) ); if ( '' !== $url && ! in_array( $url, $out, true ) ) { $out[]=$url; } } return implode( "\n", $out );
    }
    private static function detect_schema_type( \WP_Post $post ): string {
        if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) { return 'Product'; }
        if ( 'post' === $post->post_type ) { return 'Article'; }
        $slug=strtolower( (string) $post->post_name ); $title=(string) get_the_title( $post );
        if ( 'page' === $post->post_type ) {
            if ( preg_match( '/(^|[-_])(about|company|profile)([-_]|$)/i', $slug ) || preg_match( '/关于|公司简介|企业简介/u', $title ) ) { return 'AboutPage'; }
            if ( preg_match( '/(^|[-_])(contact|contact-us)([-_]|$)/i', $slug ) || preg_match( '/联系(我们)?/u', $title ) ) { return 'ContactPage'; }
        }
        $obj=get_post_type_object( $post->post_type ); $label=$obj ? (string) $obj->labels->singular_name : '';
        return preg_match( '/服务|service/i', $post->post_type . ' ' . $label ) ? 'Service' : 'WebPage';
    }
    private static function transient_key( int $user_id, int $post_id ): string { return self::TRANSIENT_PREFIX . absint( $user_id ) . '_' . absint( $post_id ); }
    private static function content_hash( string $content ): string { return hash( 'sha256', $content ); }

    private static function heading_candidates( string $content ): array {
        if ( ! function_exists( 'parse_blocks' ) ) { return array(); }
        $out=array(); self::collect_heading_candidates( parse_blocks( $content ), array(), $out ); return $out;
    }
    private static function collect_heading_candidates( array $blocks, array $base_path, array &$out ): void {
        foreach ( $blocks as $index=>$block ) {
            if ( ! is_array( $block ) ) { continue; }
            $path=array_merge( $base_path, array( (int) $index ) ); $name=(string) ( $block['blockName'] ?? '' ); $html=(string) ( $block['innerHTML'] ?? '' );
            $text=trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $html ) ) ?: '' ); $length=Utils::text_length( $text );
            if ( '' !== $text && ( 'core/heading' === $name || ( 'core/paragraph' === $name && $length <= 80 ) ) ) {
                $id='b' . implode( '-', $path ); $out[$id]=array( 'unit_id'=>$id,'text'=>self::truncate( sanitize_text_field( $text ),160 ),'type'=>$name,'level'=>'core/heading'===$name?(int)($block['attrs']['level']??2):0,'path'=>$path );
            }
            if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) { self::collect_heading_candidates( $block['innerBlocks'], $path, $out ); }
        }
    }
    private static function sanitize_ai_result( array $data, array $units, bool $supports_excerpt, bool $supports_tags ): array {
        $schema_allowed=array( 'WebPage','Article','Product','Service','AboutPage','ContactPage' ); $entity_allowed=array( 'Organization','Product','Service','Person','Place','Article' );
        $out=array(
            'seo_title'=>self::truncate( sanitize_text_field( (string)($data['seo_title']??'') ),120 ),
            'description'=>self::truncate( sanitize_textarea_field( (string)($data['description']??'') ),240 ),
            'focus_keyword'=>self::truncate( sanitize_text_field( (string)($data['focus_keyword']??'') ),100 ),
            'schema_type'=>in_array( (string)($data['schema_type']??''),$schema_allowed,true )?(string)$data['schema_type']:'WebPage',
            'excerpt'=>$supports_excerpt?self::truncate( sanitize_textarea_field( (string)($data['excerpt']??'') ),240 ):'',
            'tags'=>array(),'entity'=>self::truncate( sanitize_text_field( (string)($data['entity']??'') ),160 ),
            'entity_type'=>in_array( (string)($data['entity_type']??''),$entity_allowed,true )?(string)$data['entity_type']:'',
            'facts'=>array(),'faq'=>array(),'heading_levels'=>array(),'notes'=>array(),
        );
        if ( $supports_tags ) { foreach ( array_slice( (array)($data['tags']??array()),0,6 ) as $tag ) { $tag=self::truncate( sanitize_text_field( (string)$tag ),60 ); if ( ''!==$tag && !in_array($tag,$out['tags'],true) ){$out['tags'][]=$tag;} } }
        foreach ( array_slice( (array)($data['facts']??array()),0,12 ) as $fact ) { $fact=self::truncate( sanitize_text_field( (string)$fact ),500 ); if(''!==$fact&&!in_array($fact,$out['facts'],true)){$out['facts'][]=$fact;} }
        foreach ( array_slice( (array)($data['faq']??array()),0,8 ) as $row ) { if(!is_array($row)){continue;} $q=self::truncate(sanitize_text_field((string)($row['q']??'')),200); $a=self::truncate(sanitize_textarea_field((string)($row['a']??'')),800); if(''!==$q&&''!==$a){$out['faq'][]=array('q'=>$q,'a'=>$a);} }
        foreach ( array_slice( (array)($data['heading_levels']??array()),0,30 ) as $row ) { if(!is_array($row)){continue;} $id=sanitize_key((string)($row['unit_id']??'')); $level=(int)($row['level']??0); $original=sanitize_text_field((string)($row['original_text']??'')); if(isset($units[$id])&&in_array($level,array(2,3),true)&&$original===(string)$units[$id]['text']){$out['heading_levels'][]=array('unit_id'=>$id,'level'=>$level,'original_text'=>$original);} }
        // Preserve document order and prevent an orphan H3 from appearing before any H2.
        if ( $out['heading_levels'] ) {
            $order = array_flip( array_keys( $units ) );
            usort( $out['heading_levels'], static function( array $a, array $b ) use ( $order ): int {
                return (int) ( $order[ $a['unit_id'] ] ?? 9999 ) <=> (int) ( $order[ $b['unit_id'] ] ?? 9999 );
            } );
            $has_h2 = false;
            foreach ( $out['heading_levels'] as &$heading ) {
                if ( 3 === (int) $heading['level'] && ! $has_h2 ) { $heading['level'] = 2; }
                if ( 2 === (int) $heading['level'] ) { $has_h2 = true; }
            }
            unset( $heading );
        }
        foreach ( array_slice( (array)($data['notes']??array()),0,10 ) as $note ) { $note=self::truncate(sanitize_text_field((string)$note),500); if(''!==$note){$out['notes'][]=$note;} }
        return $out;
    }
    private static function apply_heading_plan( string $content, array $plan ): string|\WP_Error {
        if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) { return new \WP_Error( 'block_api_missing','当前 WordPress 环境缺少区块解析函数，无法安全调整 H2/H3。' ); }
        $blocks=parse_blocks($content); $by_id=array(); foreach($plan as $row){ if(!is_array($row)){continue;} $id=sanitize_key((string)($row['unit_id']??'')); $level=(int)($row['level']??0); $original=sanitize_text_field((string)($row['original_text']??'')); if(''!==$id&&in_array($level,array(2,3),true)&&''!==$original){$by_id[$id]=array('level'=>$level,'original_text'=>$original);} }
        self::apply_heading_plan_recursive($blocks,array(),$by_id); return serialize_blocks($blocks);
    }
    private static function apply_heading_plan_recursive( array &$blocks, array $base_path, array $plan ): void {
        foreach($blocks as $index=>&$block){ if(!is_array($block)){continue;} $path=array_merge($base_path,array((int)$index)); $id='b'.implode('-',$path); if(isset($plan[$id])){ $name=(string)($block['blockName']??''); if(in_array($name,array('core/paragraph','core/heading'),true)){ $text=trim(preg_replace('/\s+/u',' ',wp_strip_all_tags((string)($block['innerHTML']??'')))?:''); if($text===$plan[$id]['original_text']){self::convert_block_to_heading($block,(int)$plan[$id]['level']);} } } if(!empty($block['innerBlocks'])&&is_array($block['innerBlocks'])){self::apply_heading_plan_recursive($block['innerBlocks'],$path,$plan);} } unset($block);
    }
    private static function convert_block_to_heading( array &$block, int $level ): void {
        $level=in_array($level,array(2,3),true)?$level:2; $block['blockName']='core/heading'; $attrs=isset($block['attrs'])&&is_array($block['attrs'])?$block['attrs']:array(); $attrs['level']=$level; $block['attrs']=$attrs;
        $replace=static function(string $html)use($level):string{$html=preg_replace('/^\s*<(?:p|h[1-6])([^>]*)>/i','<h'.$level.'$1>',$html,1)??$html;$html=preg_replace('/<\/(?:p|h[1-6])>\s*$/i','</h'.$level.'>',$html,1)??$html;return $html;};
        $block['innerHTML']=$replace((string)($block['innerHTML']??'')); if(isset($block['innerContent'])&&is_array($block['innerContent'])){foreach($block['innerContent'] as $i=>$part){if(is_string($part)){$block['innerContent'][$i]=$replace($part);}}}
    }
}
