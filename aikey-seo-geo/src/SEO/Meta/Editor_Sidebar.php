<?php
namespace AIKEY\SeoGeo\SEO\Meta;

use AIKEY\SeoGeo\Core\Conflict_Detector;
use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\Support\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Gutenberg/Block Editor integration.
 *
 * Registers AIKEY post meta for REST editing and provides the editor sidebar
 * with the data it needs for real-time SEO/GEO analysis before a post is saved.
 */
final class Editor_Sidebar {
    /** @var array<string,string> */
    private const META_FIELDS = array(
        Meta_Repository::TITLE           => 'text',
        Meta_Repository::DESCRIPTION     => 'textarea',
        Meta_Repository::CANONICAL       => 'url',
        Meta_Repository::ROBOTS_INDEX    => 'robots_index',
        Meta_Repository::ROBOTS_FOLLOW   => 'robots_follow',
        Meta_Repository::FOCUS_KEYWORD   => 'text',
        Meta_Repository::CORE_KEYWORDS    => 'core_keywords',
        Meta_Repository::RELATED_KEYWORDS => 'related_keywords',
        Meta_Repository::SEARCH_QUESTIONS => 'search_questions',
        Meta_Repository::SCHEMA_TYPE     => 'schema',
        Meta_Repository::GEO_ENTITY      => 'text',
        Meta_Repository::GEO_ENTITY_TYPE => 'entity_type',
        Meta_Repository::GEO_FACTS       => 'textarea',
        Meta_Repository::GEO_FAQ         => 'textarea',
        Meta_Repository::GEO_SOURCES     => 'textarea',
    );

    public function register(): void {
        // Run late so public custom post types registered by themes/plugins are available.
        add_action( 'init', array( $this, 'register_meta' ), 99 );
        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
    }

    public function register_meta(): void {
        foreach ( Utils::public_post_types() as $post_type ) {
            // Registered post meta must be available to the REST API used by the block editor.
            if ( ! post_type_supports( $post_type, 'custom-fields' ) ) {
                add_post_type_support( $post_type, 'custom-fields' );
            }

            foreach ( self::META_FIELDS as $key => $kind ) {
                register_post_meta(
                    $post_type,
                    $key,
                    array(
                        'type'              => 'string',
                        'single'            => true,
                        'default'           => '',
                        'show_in_rest'      => true,
                        'sanitize_callback' => static function ( $value ) use ( $kind ): string {
                            return self::sanitize_value( (string) $value, $kind );
                        },
                        'auth_callback'     => static function ( $allowed, $meta_key, $object_id ): bool {
                            return current_user_can( 'edit_post', (int) $object_id );
                        },
                    )
                );
            }
        }
    }

    public function enqueue(): void {
        $screen = get_current_screen();
        if ( ! $screen || ! $screen->is_block_editor() || ! in_array( $screen->post_type, Utils::public_post_types(), true ) ) {
            return;
        }

        wp_enqueue_style(
            'aikey-seo-geo-editor-sidebar',
            AIKEY_SEO_GEO_URL . 'assets/css/editor-sidebar.css',
            array( 'wp-components' ),
            AIKEY_SEO_GEO_VERSION
        );

        wp_enqueue_script(
            'aikey-seo-geo-editor-sidebar',
            AIKEY_SEO_GEO_URL . 'assets/js/editor-sidebar.js',
            array( 'wp-plugins', 'wp-editor', 'wp-components', 'wp-element', 'wp-data', 'wp-i18n', 'wp-api-fetch', 'wp-blocks', 'wp-block-editor' ),
            AIKEY_SEO_GEO_VERSION,
            true
        );

        $schema = Settings::section( 'schema' );
        $seo    = Settings::section( 'seo_meta' );
        $tag_taxonomy = get_taxonomy( 'post_tag' );
        $tags_supported = is_object_in_taxonomy( (string) $screen->post_type, 'post_tag' ) && $tag_taxonomy && $tag_taxonomy->show_in_rest;
        $tag_rest_base = $tags_supported ? (string) ( $tag_taxonomy->rest_base ?: $tag_taxonomy->name ) : 'tags';

        wp_localize_script(
            'aikey-seo-geo-editor-sidebar',
            'AIKEY_EDITOR',
            array(
                'postId'            => (int) get_the_ID(),
                'postType'          => (string) $screen->post_type,
                'homeUrl'           => esc_url_raw( home_url( '/' ) ),
                'siteName'          => (string) get_bloginfo( 'name' ),
                'titleTemplate'     => (string) ( $seo['title_template'] ?? '%title% %sep% %sitename%' ),
                'separator'         => (string) ( $seo['separator'] ?? '-' ),
                'organizationReady' => '' !== trim( (string) ( $schema['organization_name'] ?? '' ) ),
                'sitemapEnabled'    => Settings::module_enabled( 'sitemap' ),
                'schemaEnabled'     => Settings::module_enabled( 'schema' ),
                'geoEnabled'        => Settings::module_enabled( 'geo' ),
                'aiEnabled'         => Settings::module_enabled( 'ai' ),
                'aiConfigured'      => '' !== Settings::api_key(),
                'internalLinksEnabled' => Settings::module_enabled( 'internal_links' ),
                'excerptSupported'  => post_type_supports( (string) $screen->post_type, 'excerpt' ),
                'tagsSupported'     => (bool) $tags_supported,
                'tagRestBase'       => $tag_rest_base,
                'conflict'          => Conflict_Detector::has_conflict() && ! Settings::get( 'force_takeover', false ),
                'conflictSummary'   => Conflict_Detector::summary(),
                'metaKeys'          => array(
                    'title'       => Meta_Repository::TITLE,
                    'description' => Meta_Repository::DESCRIPTION,
                    'canonical'   => Meta_Repository::CANONICAL,
                    'index'       => Meta_Repository::ROBOTS_INDEX,
                    'follow'      => Meta_Repository::ROBOTS_FOLLOW,
                    'keyword'     => Meta_Repository::FOCUS_KEYWORD,
                    'coreKeywords' => Meta_Repository::CORE_KEYWORDS,
                    'relatedKeywords' => Meta_Repository::RELATED_KEYWORDS,
                    'searchQuestions' => Meta_Repository::SEARCH_QUESTIONS,
                    'schema'      => Meta_Repository::SCHEMA_TYPE,
                    'entity'      => Meta_Repository::GEO_ENTITY,
                    'entityType'  => Meta_Repository::GEO_ENTITY_TYPE,
                    'facts'       => Meta_Repository::GEO_FACTS,
                    'faq'         => Meta_Repository::GEO_FAQ,
                    'sources'     => Meta_Repository::GEO_SOURCES,
                ),
            )
        );
    }

    private static function sanitize_value( string $value, string $kind ): string {
        switch ( $kind ) {
            case 'textarea':
                return sanitize_textarea_field( $value );
            case 'core_keywords':
                return implode( '，', self::sanitize_keywords( $value, 3 ) );
            case 'related_keywords':
                return implode( '，', self::sanitize_keywords( $value, 10 ) );
            case 'search_questions':
                return implode( "\n", self::sanitize_lines( $value, 10 ) );
            case 'url':
                return esc_url_raw( $value, array( 'http', 'https' ) );
            case 'robots_index':
                return in_array( $value, array( '', 'default', 'index', 'noindex' ), true ) ? $value : '';
            case 'robots_follow':
                return in_array( $value, array( '', 'default', 'follow', 'nofollow' ), true ) ? $value : '';
            case 'schema':
                return in_array( $value, array( '', 'WebPage', 'Article', 'Product', 'Service', 'AboutPage', 'ContactPage' ), true ) ? $value : '';
            case 'entity_type':
                return in_array( $value, array( '', 'Organization', 'Product', 'Service', 'Person', 'Place', 'Article' ), true ) ? $value : '';
            default:
                return sanitize_text_field( $value );
        }
    }
    /** @return string[] */
    private static function sanitize_keywords( string $value, int $limit ): array {
        $parts = preg_split( '/[\r\n,，;；、]+/u', $value, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
        $out = array();
        foreach ( $parts as $part ) {
            $item = trim( sanitize_text_field( (string) $part ) );
            if ( '' === $item ) { continue; }
            $item = function_exists( 'mb_substr' ) ? mb_substr( $item, 0, 80, 'UTF-8' ) : substr( $item, 0, 240 );
            $out[ $item ] = $item;
            if ( count( $out ) >= $limit ) { break; }
        }
        return array_values( $out );
    }

    /** @return string[] */
    private static function sanitize_lines( string $value, int $limit ): array {
        $parts = preg_split( '/\r\n|\r|\n/', $value ) ?: array();
        $out = array();
        foreach ( $parts as $part ) {
            $item = trim( sanitize_text_field( (string) $part ) );
            if ( '' === $item ) { continue; }
            $item = function_exists( 'mb_substr' ) ? mb_substr( $item, 0, 180, 'UTF-8' ) : substr( $item, 0, 540 );
            $out[ $item ] = $item;
            if ( count( $out ) >= $limit ) { break; }
        }
        return array_values( $out );
    }

}
