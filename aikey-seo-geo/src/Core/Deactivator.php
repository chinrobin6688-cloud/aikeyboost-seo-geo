<?php
namespace AIKEY\SeoGeo\Core;
defined( 'ABSPATH' ) || exit;
final class Deactivator {
    public static function deactivate(): void {
        wp_unschedule_hook( 'aikey_seo_geo_daily_maintenance' );
        wp_unschedule_hook( 'aikey_seo_geo_daily_incremental_submission' );
        wp_unschedule_hook( 'aikey_seo_geo_daily_sitemap_health' );
        wp_unschedule_hook( 'aikey_seo_geo_workbench_submit' );
        flush_rewrite_rules();
    }
}
