<?php
namespace AIKEY\SeoGeo\Core;

defined( 'ABSPATH' ) || exit;

final class Activator {
    public static function activate(): void {
        if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
            deactivate_plugins( plugin_basename( AIKEY_SEO_GEO_FILE ) );
            wp_die( esc_html__( 'Aikeyboost SEO&GEO 需要 PHP 8.0 或更高版本。', 'aikey-seo-geo' ) );
        }
        if ( version_compare( get_bloginfo( 'version' ), '6.2', '<' ) ) {
            deactivate_plugins( plugin_basename( AIKEY_SEO_GEO_FILE ) );
            wp_die( esc_html__( 'Aikeyboost SEO&GEO 需要 WordPress 6.2 或更高版本。', 'aikey-seo-geo' ) );
        }
        if ( false === get_option( 'aikey_seo_geo_settings', false ) ) {
            $added = add_option( 'aikey_seo_geo_settings', Settings::defaults(), '', false );
            if ( ! $added && false === get_option( 'aikey_seo_geo_settings', false ) ) {
                throw new \RuntimeException( 'Aikeyboost SEO&GEO 初始设置写入失败。' );
            }
        } elseif ( ! Settings::update( Settings::all() ) ) {
            throw new \RuntimeException( 'Aikeyboost SEO&GEO 设置兼容写入失败。' );
        }
        Database::install();
        self::store_option_or_throw( 'aikey_seo_geo_flush_rewrite', '1', 'Rewrite 刷新状态写入失败。' );
        self::store_option_or_throw( 'aikey_seo_geo_db_version', AIKEY_SEO_GEO_DB_VERSION, '数据库版本状态写入失败。' );
        self::store_option_or_throw( 'aikey_seo_geo_version', AIKEY_SEO_GEO_VERSION, '插件版本状态写入失败。' );
        flush_rewrite_rules();
    }

    private static function store_option_or_throw( string $name, string $value, string $message ): void {
        $updated = update_option( $name, $value, false );
        if ( ! $updated && (string) get_option( $name, '' ) !== $value ) {
            throw new \RuntimeException( 'Aikeyboost SEO&GEO ' . $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal error message, not rendered HTML; callers escape before display.
        }
    }
}
