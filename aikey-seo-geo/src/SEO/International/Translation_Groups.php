<?php
namespace AIKEY\SeoGeo\SEO\International;

use AIKEY\SeoGeo\Core\Database;
use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\GEO\Language;
use AIKEY\SeoGeo\SEO\Meta\Meta_Repository;
use AIKEY\SeoGeo\SEO\Sitemap\Sitemap_Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Each post_id belongs to at most one group and each group has one language
 * member. Both invariants are enforced by the database indexes, not URL slugs.
 */
final class Translation_Groups {
    /** Per-request cache only; no global site scans or extra persistent cache. */
    private static array $memo = array();

    public static function table(): string { return Database::table( 'translation_members' ); }

    public static function output_enabled(): bool {
        return ! empty( Settings::section_get( 'international', 'id_links_enabled', false ) );
    }

    /** @return array<string,array{post_id:int,language:string,group_key:string}> */
    public static function members( int $post_id ): array {
        if ( $post_id < 1 ) { return array(); }
        if ( isset( self::$memo[ $post_id ] ) ) { return self::$memo[ $post_id ]; }
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT related.post_id, related.language, related.group_key
                 FROM {$table} AS root
                 JOIN {$table} AS related ON related.group_key = root.group_key
                 WHERE root.post_id = %d", $post_id
            ), ARRAY_A
        );
        $members = array();
        foreach ( is_array( $rows ) ? $rows : array() as $row ) {
            $language = (string) $row['language'];
            $members[ $language ] = array(
                'post_id'=>(int) $row['post_id'],
                'language'=>$language,
                'group_key'=>(string) $row['group_key'],
            );
        }
        // Reuse this single indexed join result for every sibling during this request.
        foreach ( $members as $entry ) { self::$memo[ $entry['post_id'] ] = $members; }
        self::$memo[ $post_id ] = $members;
        return $members;
    }

    public static function language_for( int $post_id ): string {
        foreach ( self::members( $post_id ) as $locale=>$entry ) {
            if ( $entry['post_id'] === $post_id ) { return $locale; }
        }
        return '';
    }

    public static function count_language( string $language ): int {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE language = %s", $language
        ) );
    }

    /** Include page, post, and Taimei's verified public product_cases CPT. */
    public static function supported_type( string $type ): bool {
        $types = apply_filters( 'aikey_seo_geo_translation_types', array( 'page', 'post', 'product_cases' ) );
        $obj = get_post_type_object( $type );
        return is_array( $types ) && in_array( $type, $types, true )
            && $obj && ! empty( $obj->public );
    }

    private static function valid_pair( int $source_id, int $target_id, string $language ): bool|\WP_Error {
        $source = get_post( $source_id );
        $target = get_post( $target_id );
        if ( ! $source || ! $target || $source_id === $target_id
            || ! self::supported_type( (string) $source->post_type )
            || $source->post_type !== $target->post_type ) {
            return new \WP_Error( 'invalid_pair', '只能关联同一内容类型的两个不同页面或文章。' );
        }
        if ( 'trash' === $source->post_status || 'trash' === $target->post_status ) {
            return new \WP_Error( 'trashed_post', '请先从回收站恢复页面。' );
        }
        if ( ! Language_Registry::registered( $language ) ) {
            return new \WP_Error( 'unregistered_language', '请先在语言管理中注册目标语言。' );
        }
        return true;
    }

    /**
     * The caller must check edit_post capabilities and nonce. A member already
     * bound to a different group is never moved implicitly.
     */
    public static function bind( int $source_id, int $target_id, string $target_language, string $source_language = '' ): bool|\WP_Error {
        $target_language = Language::normalize( $target_language );
        $check = self::valid_pair( $source_id, $target_id, $target_language );
        if ( is_wp_error( $check ) ) { return $check; }

        $source = get_post( $source_id );
        $source_members = self::members( $source_id );
        $target_members = self::members( $target_id );
        $source_language = self::language_for( $source_id ) ?: Language::normalize(
            '' !== $source_language ? $source_language : Language::code( $source )
        );
        if ( ! Language_Registry::registered( $source_language ) || $source_language === $target_language ) {
            return new \WP_Error( 'source_language', '源语言没有注册或与目标语言重复。' );
        }
        if ( $target_members ) {
            $existing = self::language_for( $target_id );
            $same_key = $source_members
                && (string) reset( $target_members )['group_key'] === (string) reset( $source_members )['group_key'];
            if ( $same_key && $existing === $target_language ) { return true; }
            return new \WP_Error( 'already_assigned', '目标内容已关联到其他翻译组，请先解除原关联。' );
        }
        if ( isset( $source_members[ $target_language ] ) ) {
            return new \WP_Error( 'duplicate_language', '此翻译组已存在该语言页面，请先解除或修改关联。' );
        }

        global $wpdb;
        $table = self::table();
        $created_source = false;
        $group_key = $source_members ? (string) reset( $source_members )['group_key'] : wp_generate_uuid4();
        if ( ! $source_members ) {
            $created_source = 1 === $wpdb->insert( $table, array(
                'post_id'=>$source_id, 'group_key'=>$group_key, 'language'=>$source_language,
            ), array( '%d','%s','%s' ) );
            if ( ! $created_source ) {
                return new \WP_Error( 'group_write', '创建翻译组失败，原页面未修改。' );
            }
        }

        if ( 1 !== $wpdb->insert( $table, array(
            'post_id'=>$target_id, 'group_key'=>$group_key, 'language'=>$target_language,
        ), array( '%d','%s','%s' ) ) ) {
            if ( $created_source ) { $wpdb->delete( $table, array( 'post_id'=>$source_id ), array( '%d' ) ); }
            self::clear_memo();
            return new \WP_Error( 'group_write', '目标关联保存失败；请检查是否存在重复语言或重复页面。' );
        }
        self::changed();
        return true;
    }

    public static function unlink( int $post_id ): bool {
        if ( $post_id < 1 ) { return false; }
        global $wpdb;
        $removed = $wpdb->delete( self::table(), array( 'post_id'=>$post_id ), array( '%d' ) );
        if ( 1 === $removed ) { self::changed(); }
        return 1 === $removed;
    }

    /** Stable group key stays unchanged when one member is renamed or unlinked. */
    public static function change_language( int $post_id, string $language ): bool|\WP_Error {
        $language = Language::normalize( $language );
        if ( ! Language_Registry::registered( $language ) ) {
            return new \WP_Error( 'invalid_language', '必须先注册目标语言。' );
        }
        if ( ! self::members( $post_id ) ) {
            return new \WP_Error( 'not_linked', '当前内容没有翻译组。' );
        }
        global $wpdb;
        $changed = $wpdb->update( self::table(), array( 'language'=>$language ),
            array( 'post_id'=>$post_id ), array( '%s' ), array( '%d' ) );
        if ( false === $changed ) { return new \WP_Error( 'duplicate_language', '同一翻译组已存在该语言。' ); }
        self::changed();
        return true;
    }

    public static function create_draft( int $source_id, string $language ): int|\WP_Error {
        $source = get_post( $source_id );
        $lang = Language::normalize( $language );
        if ( ! $source || ! self::supported_type( (string) $source->post_type )
            || ! Language_Registry::registered( $lang ) || ! Language_Registry::enabled( $lang ) ) {
            return new \WP_Error( 'invalid_translation', '源内容或目标语言不可用。' );
        }
        $source_lang = self::language_for( $source_id ) ?: Language::code( $source );
        if ( $source_lang === $lang || isset( self::members( $source_id )[ $lang ] ) ) {
            return new \WP_Error( 'duplicate_language', '该语言版本已存在。' );
        }
        $id = wp_insert_post( array(
            'post_type'=>$source->post_type, 'post_status'=>'draft',
            'post_title'=>'Draft translation (' . $lang . ')',
            'post_content'=>'', 'post_excerpt'=>'', 'post_author'=>get_current_user_id(),
        ), true );
        if ( is_wp_error( $id ) ) { return $id; }
        $saved = self::bind( $source_id, (int) $id, $lang, $source_lang );
        if ( is_wp_error( $saved ) ) {
            wp_delete_post( (int) $id, true );
            return $saved;
        }
        // No source SEO/GEO meta or source-language text is copied.
        return (int) $id;
    }

    /** Only a published, indexable self-canonical WordPress post is an alternate. */
    public static function indexable_url( \WP_Post $post ): string {
        if ( 'publish' !== $post->post_status || '' !== (string) $post->post_password
            || ! (bool) get_option( 'blog_public', true )
            || ! self::supported_type( (string) $post->post_type )
            || 'noindex' === Meta_Repository::robots_index( (int) $post->ID ) ) { return ''; }
        $url = (string) get_permalink( $post );
        $canonical = Meta_Repository::resolved_canonical_url( $post );
        if ( '' === $url || '' === $canonical
            || International_Module::url_identity( $url ) !== International_Module::url_identity( $canonical ) ) {
            return '';
        }
        return $canonical;
    }

    /** Returns only real, indexable translations, using the real canonical URL. */
    public static function alternate_links( \WP_Post $post ): array {
        $members = self::members( (int) $post->ID );
        if ( count( $members ) < 2 || ! self::indexable_url( $post ) ) { return array(); }
        $links = array();
        foreach ( $members as $lang=>$row ) {
            if ( ! Language_Registry::enabled( $lang ) ) { continue; }
            $target = get_post( $row['post_id'] );
            $url = $target ? self::indexable_url( $target ) : '';
            if ( '' !== $url ) { $links[ $lang ] = $url; }
        }
        if ( count( $links ) < 2 ) { return array(); }
        $config = Settings::section( 'international' );
        $default = (string) ( $config['x_default_language'] ?? '' );
        if ( '' !== $default && isset( $links[ $default ] ) ) {
            $links['x-default'] = $links[ $default ];
        }
        return $links;
    }

    /** Public language switcher data; never exposes unpublished draft URLs. */
    public static function switcher_data( int $post_id ): array {
        $rows = self::members( $post_id );
        $current = self::language_for( $post_id ) ?: Language::code( get_post( $post_id ) ?: null );
        $links = array();
        foreach ( $rows as $lang=>$entry ) {
            $page = get_post( $entry['post_id'] );
            if ( $page && Language_Registry::enabled( $lang ) ) {
                $url = self::indexable_url( $page );
                if ( '' !== $url ) { $links[ $lang ] = $url; }
            }
        }
        $out = array();
        $current_post = $post_id ? get_post( $post_id ) : null;
        $current_url = $current_post ? self::indexable_url( $current_post ) : '';
        foreach ( Language_Registry::all() as $lang=>$config ) {
            if ( empty( $config['enabled'] ) ) { continue; }
            $url = $links[ $lang ] ?? '';
            $status = '' !== $url ? 'translation' : 'missing';
            if ( '' === $url && $lang === $current && '' !== $current_url ) {
                $url = $current_url;
                $status = 'self';
            }
            if ( '' === $url ) {
                $url = Language_Registry::home_url( $lang );
                if ( '' !== $url ) { $status = 'home_fallback'; }
            }
            $out[] = array(
                'language'=>$lang, 'name'=>(string) ( $config['name'] ?? $lang ),
                'url'=>$url, 'status'=>$status, 'current'=>$lang===$current,
                'post_id'=>isset( $links[ $lang ], $rows[ $lang ] ) ? $rows[ $lang ]['post_id']
                    : ( $status === 'self' ? $post_id : 0 ),
                'translation_url'=>isset( $links[ $lang ] ) ? $links[ $lang ] : '',
                'fallback_url'=>'home_fallback' === $status ? $url : '',
                'published'=>in_array( $status, array( 'translation','self' ), true ),
                'indexable'=>in_array( $status, array( 'translation','self' ), true ),
            );
        }
        return apply_filters( 'aikey_seo_geo_switcher_data', array(
            'current'=>$current, 'languages'=>$out,
        ), $post_id );
    }

    /** Human-readable admin metadata; includes drafts but never affects public output. */
    public static function admin_group( int $post_id ): array {
        $out = array();
        foreach ( self::members( $post_id ) as $lang=>$row ) {
            $post = get_post( $row['post_id'] );
            $out[ $lang ] = array(
                'post_id'=>$row['post_id'],
                'title'=>$post ? (string) get_the_title( $post ) : '',
                'status'=>$post ? (string) $post->post_status : 'missing',
            );
        }
        return $out;
    }

    public static function on_delete( int $post_id ): void { self::unlink( $post_id ); }

    private static function clear_memo(): void { self::$memo = array(); }

    private static function changed(): void {
        self::clear_memo();
        Sitemap_Cache::invalidate();
        do_action( 'aikey_seo_geo_translation_changed' );
    }
}
