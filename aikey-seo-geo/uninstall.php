<?php
/** Aikeyboost SEO&GEO uninstall routine. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'aikey_seo_geo_settings', array() );
$keep = is_array( $settings ) && ! empty( $settings['keep_data_on_uninstall'] );

// Scheduled events must never survive plugin removal, even when persistent
// SEO/GEO data is intentionally retained.
wp_unschedule_hook( 'aikey_seo_geo_daily_maintenance' );
wp_unschedule_hook( 'aikey_seo_geo_daily_incremental_submission' );
wp_unschedule_hook( 'aikey_seo_geo_daily_sitemap_health' );
wp_unschedule_hook( 'aikey_seo_geo_workbench_submit' );
// Authorization material is not SEO/GEO content. Always purge credentials on
// uninstall, including installations configured to keep business SEO data.
foreach ( array(
    'aikey_seo_geo_bing_webmaster_key',
    'aikey_seo_geo_bing_webmaster_site',
    'aikey_gsc_client_id',
    'aikey_gsc_client_secret_encrypted',
    'aikey_gsc_refresh_encrypted',
    'aikey_gsc_grant_marker',
    'aikey_gsc_property',
) as $secret_option ) { delete_option( $secret_option ); }
foreach ( array(
    'aikey_seo_geo_bing_search_stats',
    'aikey_seo_geo_bing_stats_lock',
    'aikey_gsc_search_analytics',
    'aikey_gsc_request_lock',
    'aikey_global_diagnostics_cooldown',
) as $cache_key ) { delete_transient( $cache_key ); }
// OAuth state and admin notices use variable per-user/hash transient keys.
global $wpdb;
foreach ( array( 'aikey_gsc_oauth_', 'aikey_gsc_notice_', 'aikey_seo_geo_webmaster_notice_' ) as $prefix ) {
    foreach ( array( '_transient_', '_transient_timeout_' ) as $start ) {
        $like = $wpdb->esc_like( $start . $prefix ) . '%';
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    }
}
if ( $keep ) { return; }

delete_option( 'aikey_seo_geo_settings' );
delete_option( 'aikey_seo_geo_version' );
delete_option( 'aikey_seo_geo_db_version' );
delete_option( 'aikey_seo_geo_flush_rewrite' );
delete_option( 'aikey_seo_geo_frontend_state' );
delete_option( 'aikey_rank_math_imported_at' );
delete_option( 'aikey_seo_geo_last_maintenance' );
delete_option( 'aikey_seo_geo_maintenance_offset' );
delete_option( 'aikey_seo_geo_daily_submission_last_run' );
delete_option( 'aikey_seo_geo_daily_submission_last_stats' );
delete_option( 'aikey_seo_geo_sitemap_health_last_run' );
delete_option( 'aikey_seo_geo_sitemap_health_last_result' );
delete_option( 'aikey_seo_geo_last_runtime_error' );
delete_option( 'aikey_seo_geo_protocol_overrides' );
delete_option( 'aikey_seo_geo_sitemap_cache_version' );
delete_option( 'aikey_seo_geo_sitemap_runtime_stats' );
delete_transient( 'aikey_seo_geo_sitemap_health_lock' );
delete_transient( 'aikey_i18n_check_cooldown' );
delete_option( 'aikey_seo_geo_baidu_over_quota_date' );

$post_meta_keys = array(
    '_aikey_seo_title','_aikey_seo_description','_aikey_seo_canonical','_aikey_seo_robots_index','_aikey_seo_robots_follow',
    '_aikey_focus_keyword','_aikey_core_keywords','_aikey_related_keywords','_aikey_search_questions','_aikey_schema_type','_aikey_geo_entity','_aikey_geo_entity_type','_aikey_geo_facts','_aikey_geo_faq',
    '_aikey_geo_sources','_aikey_geo_score','_aikey_geo_breakdown','_aikey_seo_score','_aikey_seo_issues','_aikey_ai_analysis',
    '_aikey_article_heading_backup','_aikey_article_block_backup','_aikey_article_block_converted','_aikey_article_import_pending_user',
    '_aikey_page_language','_aikey_hreflang_alternates',
    '_aikey_last_automated_at','_aikey_baidu_last_submit_sig','_aikey_indexnow_last_submit_sig','_aikey_submission_dirty_sig',
);
foreach ( $post_meta_keys as $meta_key ) { delete_post_meta_by_key( $meta_key ); }

foreach ( array( '_aikey_term_seo_title','_aikey_term_seo_description','_aikey_term_robots_index' ) as $meta_key ) {
    delete_metadata( 'term', 0, $meta_key, '', true );
}

global $wpdb;
// Remove transient AI workbench snapshots created per user/post.
$like = $wpdb->esc_like( '_transient_aikey_workbench_ai_' ) . '%';
$timeout_like = $wpdb->esc_like( '_transient_timeout_aikey_workbench_ai_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $timeout_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

// Remove all versioned Sitemap payload/marker/duplicate-owner transients. They
// contain only generated cache data and must not survive a full data purge.
foreach ( array( 'aikey_sm_', 'aikey_smm_', 'aikey_smd_' ) as $prefix ) {
    $cache_like = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
    $cache_timeout_like = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $cache_like, $cache_timeout_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}
foreach ( array( 'redirects','404_logs','referrals','crawler_logs','submission_logs','contact_messages','translation_members' ) as $suffix ) {
    $table = $wpdb->prefix . 'aikey_' . $suffix;
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
