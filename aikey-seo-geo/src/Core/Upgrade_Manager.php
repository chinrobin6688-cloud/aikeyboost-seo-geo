<?php
namespace AIKEY\SeoGeo\Core;

defined( 'ABSPATH' ) || exit;

final class Upgrade_Manager {
    public function maybe_upgrade(): void {
        $installed = (string) get_option( 'aikey_seo_geo_db_version', '0' );
        if ( version_compare( $installed, AIKEY_SEO_GEO_DB_VERSION, '<' ) ) {
            $settings = Settings::all();

            // v0.1/v0.2 中除 SEO Meta 外的模块只是占位项，默认值为 false。
            // 升级到完整 1.0 时主动启用已经真正实现的模块，避免用户升级后仍看到“最终版”功能处于关闭状态。
            if ( version_compare( $installed, '3', '<' ) ) {
                $enable = array( 'sitemap','schema','redirections','monitor_404','seo_audit','geo','llms','ai','referrals','baidu','china_engines','indexnow','crawler_monitor' );
                foreach ( $enable as $module ) { $settings['modules'][ $module ] = true; }
            }
            if ( version_compare( $installed, '11', '<' ) ) {
                foreach ( array( 'china_engines','indexnow','crawler_monitor' ) as $module ) { $settings['modules'][ $module ] = true; }
                $legacy = (string) ( $settings['geo']['ai_crawlers'] ?? 'allow' );
                if ( 'block' === $legacy && isset( $settings['china_engines']['crawler_policies'] ) && is_array( $settings['china_engines']['crawler_policies'] ) ) {
                    foreach ( $settings['china_engines']['crawler_policies'] as $id => $policy ) {
                        if ( in_array( $id, array_keys( \AIKEY\SeoGeo\Integrations\Engine_Registry::ai_engines() ), true ) ) {
                            $settings['china_engines']['crawler_policies'][ $id ] = 'block';
                        }
                    }
                }
            }

            if ( version_compare( $installed, '12', '<' ) ) {
                foreach ( array( 'automation','internal_links' ) as $module ) { $settings['modules'][ $module ] = true; }
                if ( empty( $settings['automation']['mode'] ) ) { $settings['automation']['mode'] = 'recommended'; }
            }

            if ( version_compare( $installed, '13', '<' ) ) {
                $legacy_map = isset( $settings['map'] ) && is_array( $settings['map'] ) ? $settings['map'] : array();
                $navigation = (string) ( $legacy_map['navigation_provider'] ?? 'both' );
                if ( 'same' === $navigation ) {
                    $navigation = 'baidu' === (string) ( $legacy_map['provider'] ?? 'amap' ) ? 'baidu' : 'amap';
                }
                $settings['map'] = array(
                    'height'              => min( 720, max( 220, absint( $legacy_map['height'] ?? 340 ) ) ),
                    'show_address'        => ! array_key_exists( 'show_address', $legacy_map ) || ! empty( $legacy_map['show_address'] ),
                    'show_navigation'     => ! array_key_exists( 'show_navigation', $legacy_map ) || ! empty( $legacy_map['show_navigation'] ),
                    'navigation_provider' => in_array( $navigation, array( 'amap','baidu','both' ), true ) ? $navigation : 'both',
                );
            }

            if ( version_compare( $installed, '15', '<' ) ) {
                // v2.0.12 and earlier shipped legacy automatic-submission defaults
                // of 200 (and some earlier UI flows used 50). Migrate only those
                // known defaults; preserve other administrator-selected values.
                $legacy_daily_limit = (int) ( $settings['submission']['daily_limit'] ?? 19 );
                if ( in_array( $legacy_daily_limit, array( 50, 200 ), true ) ) {
                    $settings['submission']['daily_limit'] = 19;
                }
            }

            if ( ! Settings::update( $settings ) ) {
                // Keep the old DB version so the migration is retried on the next
                // request instead of marking a partially persisted upgrade complete.
                throw new \RuntimeException( 'Aikeyboost SEO&GEO 升级设置写入失败，已停止迁移且未推进 DB Version。' );
            }
            Database::install();
            // Record the required rewrite refresh before advancing the DB version.
            // A failed state write must leave the migration retryable.
            self::store_option_or_throw( 'aikey_seo_geo_flush_rewrite', '1', '升级 Rewrite 刷新状态写入失败。' );
            self::store_option_or_throw( 'aikey_seo_geo_db_version', AIKEY_SEO_GEO_DB_VERSION, '数据库版本状态写入失败。' );
        }
        if ( (string) get_option( 'aikey_seo_geo_version', '' ) !== AIKEY_SEO_GEO_VERSION ) {
            // Plugin-only route changes (for example /sitemap.xml) must refresh
            // rewrite rules even when the DB schema version did not change. Store
            // the refresh requirement first so a failed write cannot be hidden by
            // prematurely advancing the plugin version marker.
            self::store_option_or_throw( 'aikey_seo_geo_flush_rewrite', '1', '版本升级 Rewrite 刷新状态写入失败。' );
            self::store_option_or_throw( 'aikey_seo_geo_version', AIKEY_SEO_GEO_VERSION, '插件版本状态写入失败。' );
        }
        add_action( 'init', array( $this, 'maybe_flush_rewrite' ), 99 );
    }

    private static function store_option_or_throw( string $name, string $value, string $message ): void {
        $updated = update_option( $name, $value, false );
        if ( ! $updated && (string) get_option( $name, '' ) !== $value ) {
            throw new \RuntimeException( 'Aikeyboost SEO&GEO ' . $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal error message, not rendered HTML; callers escape before display.
        }
    }

    public function maybe_flush_rewrite(): void {
        $state = ( Conflict_Detector::frontend_allowed() ? 'frontend:allowed' : 'frontend:blocked' )
            . '|' . ( Conflict_Detector::sitemap_allowed() ? 'sitemap:allowed' : 'sitemap:blocked' )
            . '|core:' . ( ! empty( Settings::section_get( 'sitemap', 'disable_core_sitemap', true ) ) ? 'off' : 'on' );
        $previous = (string) get_option( 'aikey_seo_geo_frontend_state', '' );
        $needs_flush = '1' === (string) get_option( 'aikey_seo_geo_flush_rewrite', '' ) || $state !== $previous;
        if ( $needs_flush ) {
            flush_rewrite_rules( false );
            delete_option( 'aikey_seo_geo_flush_rewrite' );
            update_option( 'aikey_seo_geo_frontend_state', $state, false );
        }
    }
}
