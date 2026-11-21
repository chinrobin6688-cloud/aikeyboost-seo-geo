<?php
namespace AIKEY\SeoGeo\Admin;

use AIKEY\SeoGeo\Analytics\Referral_Module;
use AIKEY\SeoGeo\Analytics\Crawler_Monitor_Module;
use AIKEY\SeoGeo\Analytics\Baidu_Analytics_Module;
use AIKEY\SeoGeo\Automation\Automation_Module;
use AIKEY\SeoGeo\Contacts\Contact_Repository;
use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\Core\Conflict_Detector;
use AIKEY\SeoGeo\GEO\Scorer;
use AIKEY\SeoGeo\Maps\Form_Profiles;
use AIKEY\SeoGeo\Integrations\Baidu_Submitter;
use AIKEY\SeoGeo\Integrations\Daily_Submission;
use AIKEY\SeoGeo\Integrations\Engine_Registry;
use AIKEY\SeoGeo\Integrations\Global_Crawler_Policy;
use AIKEY\SeoGeo\Integrations\IndexNow_Module;
use AIKEY\SeoGeo\Integrations\RankMath_Importer;
use AIKEY\SeoGeo\Integrations\Submission_Log;
use AIKEY\SeoGeo\Integrations\Submission_Registry;
use AIKEY\SeoGeo\Integrations\Site_Verification;
use AIKEY\SeoGeo\SEO\Audit\Audit_Module;
use AIKEY\SeoGeo\Optimization\Optimization_Workbench;
use AIKEY\SeoGeo\SEO\Redirects\Redirect_Module;
use AIKEY\SeoGeo\SEO\Sitemap\Sitemap_Module;
use AIKEY\SeoGeo\SEO\Sitemap\Sitemap_Health;
use AIKEY\SeoGeo\SEO\Sitemap\Sitemap_Content;
use AIKEY\SeoGeo\SEO\Sitemap\Sitemap_Cache;
use AIKEY\SeoGeo\SEO\Sitemap\Robots_File_Manager;
use AIKEY\SeoGeo\Support\Utils;

defined( 'ABSPATH' ) || exit;

final class Admin {

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'menu_assets' ), 5 );
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
        add_action( 'admin_post_aikey_baidu_analytics_check', array( $this, 'check_baidu_analytics' ) );
    }

    public function menu(): void {
        add_menu_page( 'Aikeyboost SEO&GEO', 'SEO&GEO', 'manage_options', 'aikey-seo-geo', array( $this, 'optimizer' ), 'dashicons-chart-area', 58 );
        $contact_unread = Contact_Repository::unread_count();
        $contact_menu = '表单信息' . ( $contact_unread > 0 ? ' <span class="awaiting-mod">' . min( 99, $contact_unread ) . '</span>' : '' );
        add_submenu_page( 'aikey-seo-geo', 'SEO/GEO 诊断优化工作台', '优化工作台', 'manage_options', 'aikey-seo-geo-optimizer', array( $this, 'optimizer' ) );
        add_submenu_page( 'aikey-seo-geo', '智能自动优化', '自动优化', 'manage_options', 'aikey-seo-geo-automation', array( $this, 'automation' ) );
        add_submenu_page( 'aikey-seo-geo', 'Sitemap / robots.txt', 'Sitemap / robots', 'manage_options', 'aikey-seo-geo-sitemap', array( $this, 'sitemap' ) );
        add_submenu_page( 'aikey-seo-geo', '搜索引擎提交中心', '提交中心', 'manage_options', 'aikey-seo-geo-submit', array( $this, 'submissions' ) );
        add_submenu_page( 'aikey-seo-geo', '站点验证与统计', '站点验证与统计', 'manage_options', 'aikey-seo-geo-verification', array( $this, 'verification' ) );
        add_submenu_page( 'aikey-seo-geo', '全球搜索 / AI 引擎', '搜索 / AI 引擎', 'manage_options', 'aikey-seo-geo-engines', array( $this, 'engines' ) );
        add_submenu_page( 'aikey-seo-geo', '404 / 重定向', '404 / 重定向', 'manage_options', 'aikey-seo-geo-redirects', array( $this, 'redirects' ) );
        add_submenu_page( 'aikey-seo-geo', '来源监测', '来源监测', 'manage_options', 'aikey-seo-geo-referrals', array( $this, 'referrals' ) );
        add_submenu_page( 'aikey-seo-geo', '表单管理', '表单管理', 'manage_options', 'aikey-seo-geo-forms', array( $this, 'forms' ) );
        add_submenu_page( 'aikey-seo-geo', '表单信息', $contact_menu, 'manage_options', 'aikey-seo-geo-contacts', array( $this, 'contacts' ) );
        add_submenu_page( 'aikey-seo-geo', '基础设置', '基础设置', 'manage_options', 'aikey-seo-geo-settings', array( $this, 'settings' ) );
        // WordPress automatically mirrors the top-level menu as the first submenu.
        // The former dashboard no longer exists, so keep the top-level click as a
        // compatibility redirect to the optimizer without showing a duplicate submenu.
        remove_submenu_page( 'aikey-seo-geo', 'aikey-seo-geo' );
    }

    /** Static menu presentation is available at first paint (no JS flash). */
    public function menu_assets(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        wp_enqueue_style( 'aikey-seo-geo-native-menu',
            AIKEY_SEO_GEO_URL . 'assets/css/admin-menu.css', array(), AIKEY_SEO_GEO_VERSION );
    }

    public function assets( string $hook ): void {
        if ( false === strpos( $hook, 'aikey-seo-geo' ) ) { return; }
        wp_enqueue_style( 'aikey-seo-geo-admin', AIKEY_SEO_GEO_URL . 'assets/css/admin.css', array(), AIKEY_SEO_GEO_VERSION );
        wp_enqueue_style( 'aikey-seo-geo-visual', AIKEY_SEO_GEO_URL . 'assets/css/admin-visual.css', array( 'aikey-seo-geo-admin' ), AIKEY_SEO_GEO_VERSION );
        wp_enqueue_style( 'aikey-seo-geo-experience', AIKEY_SEO_GEO_URL . 'assets/css/admin-experience.css', array( 'aikey-seo-geo-visual' ), AIKEY_SEO_GEO_VERSION );
        wp_enqueue_style( 'aikey-seo-geo-depth', AIKEY_SEO_GEO_URL . 'assets/css/admin-depth.css', array( 'aikey-seo-geo-experience' ), AIKEY_SEO_GEO_VERSION );
        wp_enqueue_style( 'aikey-seo-geo-stable-compat', AIKEY_SEO_GEO_URL . 'assets/css/admin-stable-compat.css', array( 'aikey-seo-geo-depth' ), AIKEY_SEO_GEO_VERSION );
        wp_enqueue_script( 'aikey-seo-geo-admin', AIKEY_SEO_GEO_URL . 'assets/js/admin.js', array(), AIKEY_SEO_GEO_VERSION, true );
    }

    public function handle_actions(): void {
        // Keep removed/merged admin pages backward compatible with old bookmarks.
        if ( isset( $_GET['page'] ) && empty( $_POST['aikey_action'] ) ) {
            $legacy_page = sanitize_key( wp_unslash( $_GET['page'] ) );
            $legacy_map = array(
                'aikey-seo-geo'         => 'aikey-seo-geo-optimizer',
                'aikey-seo-geo-baidu'   => 'aikey-seo-geo-submit',
                'aikey-seo-geo-audit'   => 'aikey-seo-geo-optimizer',
                'aikey-seo-geo-geo'     => 'aikey-seo-geo-optimizer',
                'aikey-seo-geo-import'  => 'aikey-seo-geo-settings',
                'aikey-seo-geo-modules' => 'aikey-seo-geo-settings',
            );
            if ( isset( $legacy_map[ $legacy_page ] ) ) {
                wp_safe_redirect( add_query_arg( 'page', $legacy_map[ $legacy_page ], admin_url( 'admin.php' ) ) );
                exit;
            }
        }
        if ( empty( $_POST['aikey_action'] ) ) { return; }
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( '你没有权限执行此操作。', 'aikey-seo-geo' ) ); }
        $action = sanitize_key( wp_unslash( $_POST['aikey_action'] ) );
        check_admin_referer( 'aikey_' . $action );

        switch ( $action ) {
            case 'save_settings': $this->save_settings(); break;
            case 'save_sitemap_settings': $this->save_sitemap_settings(); break;
            case 'save_submission_settings': $this->save_submission_settings(); break;
            case 'save_robots_file': $this->save_robots_file(); break;
            case 'save_protocol_overrides': $this->notice( 'warning', '旧版 Sitemap/robots 手动覆盖已取消：Sitemap 固定使用动态生成，robots.txt 改为物理文件管理。请在“Sitemap / robots”页面维护。' ); $this->go( 'aikey-seo-geo-sitemap' ); break;
            case 'save_modules': $this->notice( 'info', '模块管理已移除：核心功能默认启用，无需单独保存模块开关。' ); $this->go( 'aikey-seo-geo-settings' ); break;
            case 'add_redirect': $this->add_redirect(); break;
            case 'bulk_redirect': $this->bulk_redirect(); break;
            case 'smart_redirect_404': $this->smart_redirect_404(); break;
            case 'delete_redirect': $this->delete_redirect(); break;
            case 'clear_404': Redirect_Module::clear_404(); $this->notice( 'success', '404 日志已清空。' ); $this->go( 'aikey-seo-geo-redirects' ); break;
            case 'run_diagnostics': $this->run_diagnostics(); break;
            // Backward compatibility for old forms/bookmarks from pre-v1.8.2 pages.
            case 'run_audit': $this->run_diagnostics(); break;
            case 'run_geo': $this->run_diagnostics(); break;
            case 'workbench_save': $this->workbench_save(); break;
            case 'workbench_safe': $this->workbench_safe(); break;
            case 'workbench_rescore': $this->workbench_rescore(); break;
            case 'workbench_ai_generate': $this->workbench_ai_generate(); break;
            case 'workbench_ai_apply': $this->workbench_ai_apply(); break;
            case 'bulk_safe_optimize': $this->bulk_safe_optimize(); break;
            case 'rankmath_import': $this->rankmath_import(); break;
            case 'baidu_submit': $this->baidu_submit(); break;
            case 'save_engines': $this->save_engines(); break;
            case 'save_verifications': $this->save_verifications(); break;
            case 'save_baidu_analytics': $this->save_baidu_analytics(); break;
            case 'check_verifications': $this->check_verifications(); break;
            case 'indexnow_submit': $this->indexnow_submit(); break;
            case 'submit_all_supported': $this->submit_all_supported(); break;
            case 'save_daily_submission': $this->save_daily_submission(); break;
            case 'run_daily_submission': $this->run_daily_submission(); break;
            case 'check_sitemap_health': $this->check_sitemap_health(); break;
            case 'save_automation': $this->save_automation(); break;
            case 'run_automation': $this->run_automation(); break;
            case 'delete_contact_message': $this->delete_contact_message(); break;
            case 'save_form_profile': $this->save_form_profile(); break;
            case 'delete_form_profile': $this->delete_form_profile(); break;
            case 'clear_submission_logs': Submission_Log::clear(); $this->notice( 'success', '提交日志已清空。' ); $this->go( 'aikey-seo-geo-submit' ); break;
        }
    }

    private function save_settings(): void {
        $settings = Settings::all();
        $old_takeover = ! empty( $settings['force_takeover'] );
        $legacy_sitemap_request = isset( $_POST['sitemap_max_urls'] ) || isset( $_POST['baidu_direct_limit'] );
        $legacy_baidu_request = isset( $_POST['baidu_site'] );
        $old_disable_core = ! empty( $settings['sitemap']['disable_core_sitemap'] );

        $settings['keep_data_on_uninstall'] = isset( $_POST['keep_data_on_uninstall'] );
        $settings['force_takeover'] = isset( $_POST['force_takeover'] );
        $settings['seo_meta']['title_template'] = $this->text( 'title_template', '%title% %sep% %sitename%', 250 );
        if ( ! str_contains( $settings['seo_meta']['title_template'], '%title%' ) ) { $settings['seo_meta']['title_template'] = '%title% %sep% %sitename%'; }
        $settings['seo_meta']['separator'] = $this->text( 'separator', '-', 5 );
        $settings['seo_meta']['auto_description_fallback'] = isset( $_POST['auto_description_fallback'] );
        $settings['seo_meta']['open_graph'] = isset( $_POST['open_graph'] );

        // Legacy compatibility only. Page-level SEO (including the front page) is
        // edited in the optimization workbench / WordPress editor. Keep old values
        // as read-only fallback data unless an older client explicitly submits them.
        if ( isset( $_POST['home_title'] ) ) {
            $settings['seo_meta']['home_title'] = $this->text( 'home_title', '', 300 );
        }
        if ( isset( $_POST['home_description'] ) ) {
            $settings['seo_meta']['home_description'] = $this->textarea( 'home_description', '', 600 );
        }

        $org_type = $this->text( 'organization_type', 'Organization', 40 );
        $settings['schema']['organization_type'] = in_array( $org_type, array( 'Organization','LocalBusiness','Corporation' ), true ) ? $org_type : 'Organization';
        $settings['schema']['organization_name'] = $this->text( 'organization_name', '', 200 );
        $settings['schema']['logo_url'] = $this->url( 'logo_url' );
        $settings['schema']['telephone'] = $this->text( 'telephone', '', 80 );
        $settings['schema']['email'] = sanitize_email( $this->raw( 'email' ) );
        $settings['schema']['address'] = $this->text( 'address', '', 300 );
        $settings['schema']['same_as'] = $this->textarea( 'same_as', '', 3000 );
        $settings['schema']['icp_number'] = $this->text( 'icp_number', '', 100 );
        $settings['schema']['unified_social_credit_code'] = $this->text( 'unified_social_credit_code', '', 100 );
        $settings['schema']['service_area'] = $this->text( 'service_area', '中国大陆', 100 );
        if ( isset( $_POST['schema_localizations'] ) ) {
            if ( ! is_string( $_POST['schema_localizations'] ) ) {
                $this->notice( 'error', '多语言企业资料格式错误，原设置未修改。' );
                $this->go( 'aikey-seo-geo-settings' );
            }
            $records = \AIKEY\SeoGeo\SEO\Schema\Localized_Entity::parse(
                (string) wp_unslash( $_POST['schema_localizations'] )
            );
            if ( is_wp_error( $records ) ) {
                $this->notice( 'error', $records->get_error_message() );
                $this->go( 'aikey-seo-geo-settings' );
            }
            $settings['schema']['localized_organizations'] = $records;
        }

        $nav_provider = sanitize_key( $this->raw( 'map_navigation_provider' ) );
        $settings['map'] = array(
            'address'             => $this->text( 'map_address', '', 300 ),
            'forms'               => is_array( $settings['map']['forms'] ?? null ) ? $settings['map']['forms'] : array(),
            'height'              => min( 720, max( 220, absint( $_POST['map_height'] ?? 340 ) ) ),
            'show_address'        => isset( $_POST['map_show_address'] ),
            'show_navigation'     => isset( $_POST['map_show_navigation'] ),
            'navigation_provider' => in_array( $nav_provider, array( 'amap','baidu','both' ), true ) ? $nav_provider : 'both',
        );

        $settings['llms']['max_links'] = min( 200, max( 10, absint( $_POST['llms_max_links'] ?? 50 ) ) );

        $base = $this->url( 'ai_base_url' );
        if ( '' !== $base && 'https' === wp_parse_url( $base, PHP_URL_SCHEME ) ) { $settings['ai']['base_url'] = untrailingslashit( $base ); }
        $settings['ai']['model'] = $this->text( 'ai_model', 'deepseek-v4-flash', 100 );
        $new_key = trim( $this->raw( 'ai_api_key' ) );
        if ( '' !== $new_key ) { $settings['ai']['api_key'] = sanitize_text_field( $new_key ); }

        // Backward compatibility for cached/old v2.0.x settings forms. The v2.1
        // UI no longer exposes Sitemap or Baidu submission settings here, but an
        // older submitted form must not be silently discarded.
        if ( $legacy_sitemap_request ) {
            $settings = $this->apply_sitemap_request( $settings );
        }
        if ( $legacy_baidu_request ) {
            $settings['baidu']['site'] = $this->url( 'baidu_site' );
            $new_token = trim( $this->raw( 'baidu_token' ) );
            if ( '' !== $new_token ) { $settings['baidu']['token'] = sanitize_text_field( $new_token ); }
            $settings['baidu']['auto_submit'] = isset( $_POST['baidu_auto_submit'] );
            $settings['baidu']['auto_submit_updates'] = isset( $_POST['baidu_auto_submit_updates'] );
        }

        if ( ! Settings::update( $settings ) ) {
            $this->notice( 'error', '基础设置保存失败：数据库未确认写入，原有设置已保留。请重试并检查数据库状态。' );
            $this->go( 'aikey-seo-geo-settings' );
        }

        $takeover_changed = $old_takeover !== ! empty( $settings['force_takeover'] );
        $core_sitemap_changed = $legacy_sitemap_request && $old_disable_core !== ! empty( $settings['sitemap']['disable_core_sitemap'] );
        if ( $takeover_changed || $legacy_sitemap_request ) { Sitemap_Cache::refresh(); }
        if ( $takeover_changed || $core_sitemap_changed ) { update_option( 'aikey_seo_geo_flush_rewrite', '1', false ); }

        $this->notice( 'success', '基础设置已保存。' );
        $this->go( 'aikey-seo-geo-settings' );
    }

    private function save_sitemap_settings(): void {
        $settings = Settings::all();
        $old_disable_core = ! empty( $settings['sitemap']['disable_core_sitemap'] );
        $settings = $this->apply_sitemap_request( $settings );
        if ( ! Settings::update( $settings ) ) {
            $this->notice( 'error', 'Sitemap 设置保存失败：数据库未确认写入，原有设置已保留。' );
            $this->go( 'aikey-seo-geo-sitemap' );
        }
        Sitemap_Cache::refresh();
        if ( $old_disable_core !== ! empty( $settings['sitemap']['disable_core_sitemap'] ) ) {
            update_option( 'aikey_seo_geo_flush_rewrite', '1', false );
        }
        $this->notice( 'success', 'Sitemap 设置已保存，缓存已刷新。' );
        $this->go( 'aikey-seo-geo-sitemap' );
    }

    private function apply_sitemap_request( array $settings ): array {
        $settings['sitemap']['max_urls'] = min( 5000, max( 100, absint( $_POST['sitemap_max_urls'] ?? 5000 ) ) );
        $settings['sitemap']['disable_core_sitemap'] = isset( $_POST['sitemap_disable_core'] );
        $settings['sitemap']['baidu_direct_limit'] = min( 50000, max( 100, absint( $_POST['baidu_direct_limit'] ?? 50000 ) ) );

        $raw_ids = preg_split( '/[\s,，;；]+/u', $this->textarea( 'sitemap_exclude_post_ids', '', 50000 ) );
        $exclude_ids = array();
        foreach ( is_array( $raw_ids ) ? $raw_ids : array() as $raw_id ) {
            $id = absint( $raw_id );
            if ( $id > 0 ) { $exclude_ids[ $id ] = $id; }
            if ( count( $exclude_ids ) >= 5000 ) { break; }
        }
        $settings['sitemap']['exclude_post_ids'] = array_values( $exclude_ids );

        $raw_urls = preg_split( '/\R+/u', $this->textarea( 'sitemap_exclude_urls', '', 100000 ) );
        $exclude_urls = array();
        $home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        foreach ( is_array( $raw_urls ) ? $raw_urls : array() as $raw_url ) {
            $url = esc_url_raw( trim( (string) $raw_url ), array( 'http','https' ) );
            if ( '' === $url || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $home_host ) { continue; }
            $exclude_urls[ $url ] = $url;
            if ( count( $exclude_urls ) >= 5000 ) { break; }
        }
        $settings['sitemap']['exclude_urls'] = array_values( $exclude_urls );

        $selected_post_types = isset( $_POST['sitemap_post_types'] ) && is_array( $_POST['sitemap_post_types'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['sitemap_post_types'] ) ) : array();
        $post_type_states = isset( $settings['sitemap']['post_types'] ) && is_array( $settings['sitemap']['post_types'] ) ? $settings['sitemap']['post_types'] : array();
        foreach ( Sitemap_Content::available_post_types() as $type => $label ) { $post_type_states[ $type ] = in_array( $type, $selected_post_types, true ); }
        $settings['sitemap']['post_types'] = $post_type_states;

        $selected_taxonomies = isset( $_POST['sitemap_taxonomies'] ) && is_array( $_POST['sitemap_taxonomies'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['sitemap_taxonomies'] ) ) : array();
        $taxonomy_states = isset( $settings['sitemap']['taxonomies'] ) && is_array( $settings['sitemap']['taxonomies'] ) ? $settings['sitemap']['taxonomies'] : array();
        foreach ( Sitemap_Content::available_taxonomies() as $taxonomy => $label ) { $taxonomy_states[ $taxonomy ] = in_array( $taxonomy, $selected_taxonomies, true ); }
        $settings['sitemap']['taxonomies'] = $taxonomy_states;
        return $settings;
    }

    private function save_submission_settings(): void {
        $settings = Settings::all();
        $settings['baidu']['site'] = $this->url( 'baidu_site' );
        $new_token = trim( $this->raw( 'baidu_token' ) );
        if ( '' !== $new_token ) { $settings['baidu']['token'] = sanitize_text_field( $new_token ); }
        $settings['baidu']['auto_submit'] = isset( $_POST['baidu_auto_submit'] );
        $settings['baidu']['auto_submit_updates'] = isset( $_POST['baidu_auto_submit_updates'] );
        $settings['indexnow']['auto_submit'] = isset( $_POST['indexnow_auto_submit'] );
        $settings['submission']['daily_incremental'] = isset( $_POST['daily_incremental'] );
        $settings['submission']['daily_limit'] = min( 500, max( 1, absint( $_POST['daily_submission_limit'] ?? 19 ) ) );
        if ( ! Settings::update( $settings ) ) {
            $this->notice( 'error', '提交设置保存失败：数据库未确认写入，原有设置已保留。' );
            $this->go( 'aikey-seo-geo-submit' );
        }
        Daily_Submission::ensure_schedule();
        $this->notice( 'success', '百度、IndexNow 与每日增量设置已统一保存。' );
        $this->go( 'aikey-seo-geo-submit' );
    }

    private function save_robots_file(): void {
        try {
            $result = Robots_File_Manager::save( $this->raw( 'robots_file_content' ) );
        } catch ( \Throwable $error ) {
            $this->notice( 'error', '物理 robots.txt 写入过程发生异常，已安全中止；原文件不会被主动清空。' );
            $this->go( 'aikey-seo-geo-sitemap' );
            return;
        }
        if ( is_wp_error( $result ) ) {
            $this->notice( 'error', $result->get_error_message() );
        } else {
            $this->notice( 'success', '物理 robots.txt 已安全写入。插件已自动统一声明推荐主 Sitemap /sitemap.xml；如使用 CDN/服务器缓存，请再执行一次健康检查确认公网已更新。' );
        }
        $this->go( 'aikey-seo-geo-sitemap' );
    }

    private function add_redirect(): void {
        $source = $this->text( 'source_path', '', 400 );
        $target = $this->raw( 'target_url' );
        $status = absint( $_POST['redirect_status'] ?? 301 );
        $result = Redirect_Module::add( $source, $target, $status );
        if ( is_wp_error( $result ) ) { $this->notice( 'error', $result->get_error_message() ); }
        else { $this->notice( 'success', '重定向规则已保存。' ); }
        $this->go( 'aikey-seo-geo-redirects' );
    }

    private function bulk_redirect(): void {
        $paths = isset( $_POST['paths'] ) && is_array( $_POST['paths'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['paths'] ) ) : array();
        if ( ! $paths ) {
            $this->notice( 'error', '请先勾选需要批量处理的 404 路径。' );
            $this->go( 'aikey-seo-geo-redirects' );
        }
        $target = $this->raw( 'bulk_target_url' );
        $status = absint( $_POST['bulk_status'] ?? 301 );
        if ( 410 !== $status && '' === trim( $target ) ) {
            $this->notice( 'error', '批量 301/302/307/308 需要填写目标地址；如需永久删除请选择 410。' );
            $this->go( 'aikey-seo-geo-redirects' );
        }
        $stats = Redirect_Module::bulk_add( $paths, $target, $status );
        $this->notice( 'success', sprintf( '批量处理完成：成功 %d 条，跳过 %d 条，失败 %d 条。', (int) $stats['success'], (int) $stats['skipped'], (int) $stats['failed'] ) );
        $this->go( 'aikey-seo-geo-redirects' );
    }

    private function smart_redirect_404(): void {
        $stats = Redirect_Module::smart_redirect_404( 200 );
        // The user explicitly requested a clean queue after a smart pass. High-confidence
        // matches are converted to redirects first; all remaining 404 observations are then
        // cleared so the next log view contains only new requests after this run.
        Redirect_Module::clear_404();
        if ( (int) $stats['success'] > 0 ) {
            $message = sprintf( '智能重定向完成：自动创建 %d 条高置信度 301；跳过 %d 条不确定路径；识别 %d 条扫描/探测路径；失败 %d 条。404 日志已自动清空。', (int) $stats['success'], (int) $stats['skipped'], (int) $stats['probes'], (int) $stats['failed'] );
            $this->notice( 'success', $message );
        } else {
            $message = sprintf( '没有发现可安全自动重定向的高置信度路径。跳过 %d 条不确定路径，识别 %d 条扫描/探测路径；404 日志已自动清空。插件不会把未知路径统一重定向到首页。', (int) $stats['skipped'], (int) $stats['probes'] );
            $this->notice( 'warning', $message );
        }
        $this->go( 'aikey-seo-geo-redirects' );
    }

    private function delete_redirect(): void {
        Redirect_Module::delete( absint( $_POST['redirect_id'] ?? 0 ) );
        $this->notice( 'success', '重定向规则已删除。' );
        $this->go( 'aikey-seo-geo-redirects' );
    }

    private function run_diagnostics(): void {
        $posts = get_posts( array(
            'post_type'      => Utils::public_post_types(),
            'post_status'    => 'publish',
            'posts_per_page' => 300,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ) );
        $processed = 0;
        foreach ( $posts as $post ) {
            if ( ! $post instanceof \WP_Post ) { continue; }
            Audit_Module::persist( $post );
            Scorer::persist( $post );
            $processed++;
        }
        $this->notice( 'success', 'SEO/GEO 统一诊断完成，已重新检测最近更新的 ' . $processed . ' 个公开页面。' );
        $this->go( 'aikey-seo-geo-optimizer' );
    }

    private function rankmath_import(): void {
        $stats = RankMath_Importer::import( isset( $_POST['overwrite'] ) );
        $this->notice( 'success', sprintf( 'Rank Math 导入完成：处理 %d 个页面，写入 %d 个字段，跳过 %d 个已有字段。', $stats['posts'], $stats['fields'], $stats['skipped'] ) );
        $this->go( 'aikey-seo-geo-settings' );
    }

    private function baidu_submit(): void {
        $limit = min( Baidu_Submitter::SAFE_BATCH_LIMIT, max( 1, absint( $_POST['baidu_limit'] ?? Baidu_Submitter::SAFE_BATCH_LIMIT ) ) );
        $result = Baidu_Submitter::submit_urls( Baidu_Submitter::recent_urls( $limit ) );
        if ( is_wp_error( $result ) ) {
            $type = in_array( $result->get_error_code(), array( 'baidu_over_quota','baidu_over_quota_cached' ), true ) ? 'warning' : 'error';
            $this->notice( $type, $result->get_error_message() );
        } else { $this->notice( 'success', '百度提交完成：成功 ' . (int) ( $result['success'] ?? 0 ) . ' 条；剩余配额 ' . (int) ( $result['remain'] ?? 0 ) . '。' ); }
        $this->go( 'aikey-seo-geo-submit' );
    }

    private function submit_all_supported(): void {
        $limit = min( 200, max( 1, absint( $_POST['submit_all_limit'] ?? 19 ) ) );
        $urls = Sitemap_Module::manual_submission_urls( $limit );
        $results = array();

        $indexnow = IndexNow_Module::submit_urls( $urls, 'manual_all' );
        $results[] = is_wp_error( $indexnow ) ? 'IndexNow失败：' . $indexnow->get_error_message() : 'IndexNow成功 ' . (int) ( $indexnow['success'] ?? 0 ) . ' 条';

        $s = Settings::section( 'baidu' );
        if ( ! empty( $s['site'] ) && ! empty( $s['token'] ) ) {
            $baidu_urls = array_slice( $urls, 0, Baidu_Submitter::SAFE_BATCH_LIMIT );
            $baidu = Baidu_Submitter::submit_urls( $baidu_urls, 'manual_all' );
            $results[] = is_wp_error( $baidu ) ? '百度失败：' . $baidu->get_error_message() : '百度成功 ' . (int) ( $baidu['success'] ?? 0 ) . ' 条';
        } else {
            $results[] = '百度跳过：尚未配置已验证站点/API Token';
        }

        $has_success = false;
        foreach ( $results as $item ) {
            if ( str_contains( $item, '成功' ) ) { $has_success = true; break; }
        }
        $this->notice( $has_success ? 'success' : 'warning', '可自动通道提交完成：' . implode( '；', $results ) . '。其余搜索/AI平台没有公开稳定的通用服务端提交 API，已在提交中心提供官方入口。' );
        $this->go( 'aikey-seo-geo-submit' );
    }

    private function save_engines(): void {
        $settings = Settings::all();
        $platforms = Engine_Registry::platforms();
        foreach ( $platforms as $id => $platform ) {
            $settings['china_engines']['enabled'][ $id ] = isset( $_POST['engine_enabled_' . $id] );
            if ( ! empty( $platform['bots'] ) ) {
                $policy = sanitize_key( $this->raw( 'engine_policy_' . $id ) );
                $settings['china_engines']['crawler_policies'][ $id ] = in_array( $policy, array( 'allow','block','unchanged' ), true ) ? $policy : 'allow';
            }
        }
        // Missing/invalid per-bot selections inherit platform rules; no arbitrary UA can be injected.
        // Preserve token preferences when a stale pre-upgrade admin form is posted.
        if ( isset( $_POST['global_bot_policy_present'] ) && '1' === (string) $_POST['global_bot_policy_present'] ) {
            $submitted_bot_policies = $_POST['global_bot_policy'] ?? array();
            $settings['china_engines']['bot_policies'] = Global_Crawler_Policy::sanitize_policies(
                is_array( $submitted_bot_policies ) ? wp_unslash( $submitted_bot_policies ) : array()
            );
        }
        // Site ownership verification is managed by the dedicated verification center.
        // Keep legacy fields untouched here so saving crawler settings can never erase verification tokens.
        $settings['china_engines']['baidu_location_province'] = $this->text( 'baidu_location_province', '', 80 );
        $settings['china_engines']['baidu_location_city'] = $this->text( 'baidu_location_city', '', 80 );
        $settings['china_engines']['baidu_location_coord'] = $this->text( 'baidu_location_coord', '', 80 );
        $settings['china_engines']['baidu_applicable_device'] = isset( $_POST['baidu_applicable_device'] );

        if ( ! Settings::update( $settings ) ) {
            $this->notice( 'error', '搜索 / AI 引擎设置保存失败：数据库未确认写入，未同步 robots.txt。' );
            $this->go( 'aikey-seo-geo-engines' );
        }
        $robots_sync = true;
        try { $robots_sync = Robots_File_Manager::sync_managed_block(); } catch ( \Throwable $error ) { $robots_sync = new \WP_Error( 'robots_sync_failed', '物理 robots.txt crawler 策略同步失败。' ); }
        if ( is_wp_error( $robots_sync ) ) {
            $this->notice( 'warning', '搜索 / AI 引擎设置已保存，但物理 robots.txt 的 crawler 管理块未同步：' . $robots_sync->get_error_message() );
        } else {
            $this->notice( 'success', '搜索 / AI 引擎设置已保存；如已创建物理 robots.txt，其 crawler 管理块已同步。' );
        }
        $this->go( 'aikey-seo-geo-engines' );
    }

    private function save_baidu_analytics(): void {
        $settings = Settings::all();
        $analytics_raw = trim( $this->raw( 'baidu_analytics_site_id' ) );
        $previous_analytics_id = Baidu_Analytics_Module::site_id();
        $analytics_warning = '';

        if ( '' === $analytics_raw ) {
            $analytics_id = '';
        } else {
            $analytics_id = Baidu_Analytics_Module::normalize_site_id_input( $analytics_raw );
            if ( '' === $analytics_id ) {
                $analytics_id = $previous_analytics_id;
                $analytics_warning = '百度统计 Site ID 格式无效：原有 Site ID 已保留。请填写百度统计 Site ID，或粘贴包含 hm.baidu.com/hm.js?ID 的官方代码。';
            }
        }

        $settings['baidu_analytics']['site_id'] = $analytics_id;
        $settings['baidu_analytics']['exclude_admins'] = isset( $_POST['baidu_analytics_exclude_admins'] );
        $analytics_requested = isset( $_POST['baidu_analytics_enabled'] );
        $settings['modules']['baidu_analytics'] = $analytics_requested && '' !== $analytics_id;

        if ( $analytics_requested && '' === $analytics_id && '' === $analytics_warning ) {
            $analytics_warning = '百度统计未启用：请先填写有效的百度统计 Site ID。';
        }

        if ( ! Settings::update( $settings ) ) {
            $this->notice( 'error', '百度统计设置保存失败：数据库未确认写入，原有设置已保留。' );
            $this->go( 'aikey-seo-geo-verification' );
        }
        $this->notice( '' !== $analytics_warning ? 'warning' : 'success', '' !== $analytics_warning ? $analytics_warning : '百度统计设置已保存。' );
        $this->go( 'aikey-seo-geo-verification' );
    }

    public function check_baidu_analytics(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( '你没有权限执行此操作。', 'aikey-seo-geo' ) );
        }
        check_admin_referer( 'aikey_baidu_analytics_check' );

        $result = Baidu_Analytics_Module::check_homepage();
        if ( is_wp_error( $result ) ) {
            $this->notice( 'warning', '百度统计检测未完成：' . $result->get_error_message() );
            $this->go( 'aikey-seo-geo-verification' );
        }

        $ids = isset( $result['ids'] ) && is_array( $result['ids'] ) ? $result['ids'] : array();
        $counts = isset( $result['counts'] ) && is_array( $result['counts'] ) ? $result['counts'] : array();
        $configured = (string) ( $result['configured'] ?? '' );
        if ( ! $ids ) {
            $this->notice( 'warning', '公开首页源码中暂未检测到百度统计 hm.js。若刚启用，请先清理页面缓存/CDN缓存后重试；若由特殊 JS 动态注入，也可能无法通过源码检测识别。' );
            $this->go( 'aikey-seo-geo-verification' );
        }

        $total_occurrences = array_sum( array_map( 'absint', $counts ) );
        if ( count( $ids ) > 1 ) {
            $this->notice( 'warning', '检测到多个不同的百度统计 Site ID：' . implode( '、', array_map( 'sanitize_text_field', $ids ) ) . '。请检查主题、其他插件或历史代码，避免重复统计。' );
            $this->go( 'aikey-seo-geo-verification' );
        }
        if ( $total_occurrences > 1 ) {
            $this->notice( 'warning', '公开首页检测到同一个百度统计 Site ID 被安装了 ' . $total_occurrences . ' 次。请只保留一套统计代码，避免重复安装。' );
            $this->go( 'aikey-seo-geo-verification' );
        }

        $detected = (string) $ids[0];
        if ( '' !== $configured && hash_equals( $configured, $detected ) ) {
            $this->notice( 'success', '百度统计安装检测通过：公开首页已检测到当前配置的 Site ID ' . $detected . '。' );
        } elseif ( '' !== $configured ) {
            $this->notice( 'warning', '公开首页检测到百度统计 Site ID ' . $detected . '，但与插件当前配置 ' . $configured . ' 不一致。请检查是否由主题或其他插件重复安装。' );
        } else {
            $this->notice( 'warning', '网站公开首页已经存在百度统计 Site ID ' . $detected . '。如果这是主题或其他插件安装的代码，请不要再通过 Aikeyboost重复启用。' );
        }
        $this->go( 'aikey-seo-geo-verification' );
    }

    private function save_verifications(): void {
        $settings = Settings::all();
        $entries = array();
        $errors = array();

        foreach ( Site_Verification::profiles() as $id => $profile ) {
            $raw = $this->raw( 'verification_' . $id );
            if ( '' === trim( $raw ) ) { continue; }
            $parsed = Site_Verification::parse_single_input( $raw, (string) ( $profile['default_name'] ?? '' ) );
            if ( is_wp_error( $parsed ) ) {
                $errors[] = (string) ( $profile['label'] ?? $id ) . '：' . $parsed->get_error_message();
                continue;
            }
            if ( '' !== (string) ( $parsed['name'] ?? '' ) && '' !== (string) ( $parsed['content'] ?? '' ) ) {
                $entries[ $id ] = $parsed;
            }
        }

        $custom_raw = $this->raw( 'verification_custom' );
        $custom = array();
        if ( '' !== trim( $custom_raw ) ) {
            $parsed_custom = Site_Verification::parse_custom_input( $custom_raw );
            if ( is_wp_error( $parsed_custom ) ) { $errors[] = '其他搜索引擎：' . $parsed_custom->get_error_message(); }
            else { $custom = $parsed_custom; }
        }

        if ( $errors ) {
            $this->notice( 'error', '验证代码未保存：' . implode( '；', $errors ) );
            $this->go( 'aikey-seo-geo-verification' );
            return;
        }

        $settings['site_verification']['entries'] = $entries;
        $settings['site_verification']['custom'] = $custom;
        // Once the dedicated verification center has been saved it becomes
        // authoritative. Clear legacy values so a user can actually remove a
        // previously configured verification code instead of having it fall
        // back from china_engines.verification.
        if ( ! isset( $settings['china_engines']['verification'] ) || ! is_array( $settings['china_engines']['verification'] ) ) {
            $settings['china_engines']['verification'] = array();
        }
        foreach ( Site_Verification::profiles() as $id => $profile ) {
            $settings['china_engines']['verification'][ $id ] = '';
        }
        if ( ! Settings::update( $settings ) ) {
            $this->notice( 'error', '站点验证代码保存失败：数据库未确认写入，原有验证设置已保留。' );
            $this->go( 'aikey-seo-geo-verification' );
        }
        Site_Verification::purge_caches();
        $this->notice( 'success', '站点验证代码已保存，并已尝试清理 WordPress/常见页面缓存。验证 Meta 将输出到前台 HTML 的 <head> 中；如使用 CDN/Nginx 页面缓存，请同时清理对应缓存。' );
        $this->go( 'aikey-seo-geo-verification' );
    }

    private function check_verifications(): void {
        $result = Site_Verification::check_public_homepage();
        if ( is_wp_error( $result ) ) {
            $this->notice( 'error', '首页验证代码检测失败：' . $result->get_error_message() );
            $this->go( 'aikey-seo-geo-verification' );
            return;
        }
        $checked = (int) ( $result['checked'] ?? 0 );
        $matched = (int) ( $result['matched'] ?? 0 );
        $missing = isset( $result['missing'] ) && is_array( $result['missing'] ) ? $result['missing'] : array();
        if ( $checked > 0 && $checked === $matched ) {
            $this->notice( 'success', '公开首页检测通过：已核对 ' . $matched . '/' . $checked . ' 条验证 Meta，name 与 content 均与插件保存值一致。' );
        } else {
            $detail = $missing ? ' 缺失或不一致：' . implode( ', ', array_map( 'sanitize_text_field', $missing ) ) . '。' : '';
            $this->notice( 'warning', '公开首页只匹配 ' . $matched . '/' . $checked . ' 条验证 Meta。' . $detail . ' 请先清理页面/CDN缓存，再重新检测。' );
        }
        $this->go( 'aikey-seo-geo-verification' );
    }

    private function indexnow_submit(): void {
        $limit = min( 1000, max( 1, absint( $_POST['indexnow_limit'] ?? 100 ) ) );
        $result = IndexNow_Module::submit_urls( IndexNow_Module::recent_urls( $limit ) );
        if ( is_wp_error( $result ) ) { $this->notice( 'error', $result->get_error_message() ); }
        else { $this->notice( 'success', 'IndexNow 提交完成：成功提交 ' . (int) ( $result['success'] ?? 0 ) . ' 个 URL，HTTP ' . (int) ( $result['status'] ?? 0 ) . '。' ); }
        $return = sanitize_key( $this->raw( 'return_page' ) );
        $this->go( in_array( $return, array( 'aikey-seo-geo-engines','aikey-seo-geo-submit' ), true ) ? $return : 'aikey-seo-geo-submit' );
    }

    private function save_daily_submission(): void {
        $settings = Settings::all();
        $settings['submission']['daily_incremental'] = isset( $_POST['daily_incremental'] );
        $settings['submission']['daily_limit'] = min( 500, max( 1, absint( $_POST['daily_submission_limit'] ?? 19 ) ) );
        if ( ! Settings::update( $settings ) ) {
            $this->notice( 'error', '每日增量自动提交设置保存失败：数据库未确认写入。' );
            $this->go( 'aikey-seo-geo-submit' );
        }
        Daily_Submission::ensure_schedule();
        $this->notice( 'success', '每日增量自动提交设置已保存。' );
        $this->go( 'aikey-seo-geo-submit' );
    }

    private function run_daily_submission(): void {
        $stats = Daily_Submission::run( true );
        $parts = array();
        foreach ( array( 'indexnow'=>'IndexNow', 'baidu'=>'百度' ) as $key => $label ) {
            $row = isset( $stats[ $key ] ) && is_array( $stats[ $key ] ) ? $stats[ $key ] : array();
            $submitted = (int) ( $row['submitted'] ?? 0 );
            $message = trim( (string) ( $row['message'] ?? '' ) );
            $parts[] = $label . '：' . ( $submitted > 0 ? '提交 ' . $submitted . ' 条' : $message );
        }
        $this->notice( 'success', '每日增量任务执行完成。' . implode( '；', array_filter( $parts ) ) );
        $this->go( 'aikey-seo-geo-submit' );
    }

    private function check_sitemap_health(): void {
        $result = Sitemap_Health::run( true );
        $sitemap = isset( $result['sitemap'] ) && is_array( $result['sitemap'] ) ? $result['sitemap'] : array();
        $robots = isset( $result['robots'] ) && is_array( $result['robots'] ) ? $result['robots'] : array();
        $sitemap_ok = 'success' === (string) ( $sitemap['status'] ?? '' );
        $robots_ok = 'success' === (string) ( $robots['status'] ?? '' );
        $message = 'Sitemap：' . sanitize_text_field( (string) ( $sitemap['message'] ?? '未完成检查。' ) )
            . '；robots.txt：' . sanitize_text_field( (string) ( $robots['message'] ?? '未完成检查。' ) );
        $this->notice( $sitemap_ok && $robots_ok ? 'success' : 'warning', $message );
        $this->go( 'aikey-seo-geo-sitemap' );
    }

    private function save_automation(): void {
        $settings = Settings::all();
        $mode = sanitize_key( $this->raw( 'automation_mode' ) );
        $settings['automation']['mode'] = in_array( $mode, array( 'conservative','recommended','full' ), true ) ? $mode : 'recommended';
        $settings['automation']['optimize_on_save'] = isset( $_POST['optimize_on_save'] );
        $settings['automation']['auto_score'] = isset( $_POST['auto_score'] );
        $settings['automation']['auto_schema'] = isset( $_POST['auto_schema'] );
        $settings['automation']['fill_empty_entity'] = isset( $_POST['fill_empty_entity'] );
        $settings['automation']['daily_maintenance'] = isset( $_POST['daily_maintenance'] );
        $settings['automation']['daily_scan_limit'] = min( 500, max( 20, absint( $_POST['daily_scan_limit'] ?? 200 ) ) );
        // Search-engine submission is owned by the Submission Center. Do not
        // mutate Baidu / IndexNow settings from the automation page.
        if ( ! Settings::update( $settings ) ) {
            $this->notice( 'error', '智能自动优化设置保存失败：数据库未确认写入。' );
            $this->go( 'aikey-seo-geo-automation' );
        }
        $this->notice( 'success', '智能自动优化设置已保存。' );
        $this->go( 'aikey-seo-geo-automation' );
    }

    private function run_automation(): void {
        $limit = min( 500, max( 20, absint( $_POST['automation_limit'] ?? 200 ) ) );
        $result = Automation_Module::run_now( $limit );
        $this->notice( 'success', '自动优化巡检完成：处理 ' . (int) ( $result['processed'] ?? 0 ) . ' 个公开页面。' );
        $this->go( 'aikey-seo-geo-automation' );
    }

    public function redirects(): void {
        $this->page_title( '404 / 重定向', '精确路径重定向、批量处理与 404 聚合监控；不记录访客 IP。' ); $this->render_notice();
        $prefill = isset( $_GET['source'] ) ? Utils::normalize_path( sanitize_text_field( wp_unslash( $_GET['source'] ) ) ) : '';

        $per_page = 10;
        $total_rules = Redirect_Module::count_redirects();
        $total_pages = max( 1, (int) ceil( $total_rules / $per_page ) );
        $redirect_page = max( 1, absint( $_GET['redirect_page'] ?? 1 ) );
        $redirect_page = min( $redirect_page, $total_pages );
        $redirect_rows = Redirect_Module::list_redirects_page( $redirect_page, $per_page );

        $total_logs = Redirect_Module::count_404();
        $log_pages = max( 1, (int) ceil( $total_logs / $per_page ) );
        $log_page = max( 1, absint( $_GET['log_page'] ?? 1 ) );
        $log_page = min( $log_page, $log_pages );
        $logs = Redirect_Module::list_404_page( $log_page, $per_page );
        ?>
        <div class="aikey-panel">
            <div class="aikey-panel-head"><div><h2>一键智能重定向</h2><p class="description">只对能够高置信度匹配到现有已发布页面的旧路径自动建立 301。恶意扫描、WordPress 探测和无法确定目标的 404 会跳过，不会统一重定向到首页，避免形成软 404。运行完成后 404 日志会自动清空，只保留之后新产生的 404。</p></div>
            <form method="post"><?php wp_nonce_field( 'aikey_smart_redirect_404' ); ?><input type="hidden" name="aikey_action" value="smart_redirect_404"><button class="button button-primary">一键智能重定向</button></form></div>
        </div>
        <div class="aikey-panel"><h2>新增 / 更新规则</h2><form method="post" class="aikey-inline-form"><?php wp_nonce_field( 'aikey_add_redirect' ); ?><input type="hidden" name="aikey_action" value="add_redirect"><input type="text" name="source_path" required placeholder="/old-page" value="<?php echo esc_attr( $prefill ); ?>"><input type="text" name="target_url" placeholder="/new-page 或 https://..."><select name="redirect_status"><option>301</option><option>302</option><option>307</option><option>308</option><option>410</option></select><button class="button button-primary">保存规则</button></form><p class="description">410 表示资源永久删除，不需要目标地址。</p></div>
        <div class="aikey-panel"><div class="aikey-panel-head"><div><h2>重定向规则</h2><p class="description">共 <?php echo esc_html( (string) $total_rules ); ?> 条规则，每页最多显示 10 条。</p></div></div><table class="widefat striped"><thead><tr><th>来源</th><th>目标</th><th>状态</th><th>命中</th><th>最近命中</th><th></th></tr></thead><tbody><?php if ( ! $redirect_rows ) : ?><tr><td colspan="6">暂无重定向规则。</td></tr><?php else : foreach ( $redirect_rows as $row ) : ?><tr><td><code><?php echo esc_html( $row->source_path ); ?></code></td><td><?php echo esc_html( $row->target_url ?: '—' ); ?></td><td><?php echo esc_html( (string) $row->status ); ?></td><td><?php echo esc_html( (string) $row->hits ); ?></td><td><?php echo esc_html( (string) $row->last_hit ); ?></td><td><form method="post"><?php wp_nonce_field( 'aikey_delete_redirect' ); ?><input type="hidden" name="aikey_action" value="delete_redirect"><input type="hidden" name="redirect_id" value="<?php echo esc_attr( (string) $row->id ); ?>"><button class="button-link-delete">删除</button></form></td></tr><?php endforeach; endif; ?></tbody></table>
        <?php if ( $total_pages > 1 ) :
            $big = 999999999;
            $base = str_replace( (string) $big, '%#%', add_query_arg( array( 'page'=>'aikey-seo-geo-redirects', 'redirect_page'=>$big, 'log_page'=>$log_page ), admin_url( 'admin.php' ) ) );
            $links = paginate_links( array( 'base'=>$base, 'format'=>'', 'current'=>$redirect_page, 'total'=>$total_pages, 'type'=>'list', 'prev_text'=>'‹ 上一页', 'next_text'=>'下一页 ›' ) );
            if ( $links ) { echo '<nav class="aikey-pagination" aria-label="重定向规则分页">' . wp_kses_post( $links ) . '</nav>'; }
        endif; ?>
        </div>
        <div class="aikey-panel">
            <div class="aikey-panel-head"><div><h2>404 日志</h2><p class="description">共 <?php echo esc_html( (string) $total_logs ); ?> 条记录，每页最多显示 10 条。可勾选当前页多条后批量重定向到同一个新地址；若这些 URL 已永久删除，可批量选择 410。</p></div><form method="post"><?php wp_nonce_field( 'aikey_clear_404' ); ?><input type="hidden" name="aikey_action" value="clear_404"><button class="button">清空日志</button></form></div>
            <form method="post" id="aikey-bulk-redirect-form"><?php wp_nonce_field( 'aikey_bulk_redirect' ); ?><input type="hidden" name="aikey_action" value="bulk_redirect">
            <table class="widefat striped"><thead><tr><th style="width:36px"><input type="checkbox" id="aikey-select-all-404" aria-label="全选当前页"></th><th>路径</th><th>来源</th><th>次数</th><th>最近</th><th></th></tr></thead><tbody><?php if ( ! $logs ) : ?><tr><td colspan="6">暂无 404 日志。</td></tr><?php else : foreach ( $logs as $row ) : ?><tr><td><input type="checkbox" class="aikey-404-checkbox" name="paths[]" value="<?php echo esc_attr( $row->path ); ?>"></td><td><code><?php echo esc_html( $row->path ); ?></code></td><td><?php echo esc_html( $row->referrer ?: '直接访问/未知' ); ?></td><td><?php echo esc_html( (string) $row->hits ); ?></td><td><?php echo esc_html( get_date_from_gmt( (string) $row->last_seen, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td><td><a href="<?php echo esc_url( add_query_arg( array( 'page'=>'aikey-seo-geo-redirects','source'=>$row->path,'redirect_page'=>$redirect_page,'log_page'=>$log_page ), admin_url( 'admin.php' ) ) ); ?>">单独设置</a></td></tr><?php endforeach; endif; ?></tbody></table>
            <div class="aikey-bulk-bar"><strong>批量操作：</strong><input type="text" name="bulk_target_url" placeholder="统一目标：/new-page 或 https://..."><select name="bulk_status"><option value="301">301 永久重定向</option><option value="302">302 临时重定向</option><option value="307">307 临时重定向</option><option value="308">308 永久重定向</option><option value="410">410 永久删除</option></select><button class="button button-primary">批量处理所选</button><span class="description">不要把所有未知 404 一律重定向到首页。</span></div>
            </form>
            <?php if ( $log_pages > 1 ) :
                $big = 999999999;
                $base = str_replace( (string) $big, '%#%', add_query_arg( array( 'page'=>'aikey-seo-geo-redirects', 'redirect_page'=>$redirect_page, 'log_page'=>$big ), admin_url( 'admin.php' ) ) );
                $links = paginate_links( array( 'base'=>$base, 'format'=>'', 'current'=>$log_page, 'total'=>$log_pages, 'type'=>'list', 'prev_text'=>'‹ 上一页', 'next_text'=>'下一页 ›' ) );
                if ( $links ) { echo '<nav class="aikey-pagination" aria-label="404 日志分页">' . wp_kses_post( $links ) . '</nav>'; }
            endif; ?>
        </div></div>
        <script>document.addEventListener('DOMContentLoaded',function(){var all=document.getElementById('aikey-select-all-404');if(!all)return;all.addEventListener('change',function(){document.querySelectorAll('.aikey-404-checkbox').forEach(function(cb){cb.checked=all.checked;});});});</script>
        <?php
    }

    /** One settings page for any number of language-specific map/contact forms. */
    public function forms(): void {
        $profiles = Form_Profiles::all();
        $id = isset( $_GET['form_id'] ) && is_string( $_GET['form_id'] )
            ? sanitize_key( wp_unslash( $_GET['form_id'] ) ) : '';
        $selected = isset( $profiles[ $id ] ) && is_array( $profiles[ $id ] ) ? $profiles[ $id ] : array();
        $editing = '' !== $id && ! empty( $selected );
        $this->page_title( '多语言表单管理', '为同一网站的不同语言页面创建独立的表单及地图内容；使用不同短代码调用，留言按表单编号分类。' );
        $this->render_notice();
        echo '<div class="aikey-panel"><h2>使用方法</h2><p>默认表单（兼容旧页面）：<code>[aikey_enterprise_map]</code>；仅显示联系表单：<code>[aikey_contact_form]</code>。</p>';
        echo '<p>创建表单后，将生成的短代码复制到对应语言页面的“短代码”区块。例如英文页面使用 <code>[aikey_enterprise_map form="en"]</code>，仅展示英文联系表单使用 <code>[aikey_contact_form form="en"]</code>。</p>';
        echo '<p>默认表单的全站地图地址、高度和导航设置仍在 <a href="' . esc_url( admin_url( 'admin.php?page=aikey-seo-geo-settings#aikey-enterprise-map-settings' ) ) . '">基础设置 → 企业地图与联系表单</a>。</p>';
        echo '<p class="description">非中文表单使用相应语言的地图标题和 Google Maps 入口；中文默认表单保留高德、百度地图。请为海外表单填写对应语言的企业名称和地址。所有表单的电话、邮箱和留言均为必填。</p></div>';

        echo '<div class="aikey-panel"><h2>已创建的表单</h2><table class="widefat striped"><thead><tr><th>表单编号</th><th>后台名称</th><th>语言</th><th>页面短代码</th><th>操作</th></tr></thead><tbody>';
        foreach ( $profiles as $key=>$profile ) {
            if ( ! is_array( $profile ) ) { continue; }
            $url = add_query_arg( array( 'page'=>'aikey-seo-geo-forms', 'form_id'=>$key ), admin_url( 'admin.php' ) );
            echo '<tr><td><code>' . esc_html( (string) $key ) . '</code></td><td>' . esc_html( (string) ( $profile['label'] ?? $key ) ) . '</td><td>' . esc_html( (string) ( Form_Profiles::languages()[ $profile['language'] ?? 'en' ] ?? 'English' ) ) . '</td>';
            echo '<td><code>[aikey_enterprise_map form="' . esc_html( (string) $key ) . '"]</code><p class="description"><code>[aikey_contact_form form="' . esc_html( (string) $key ) . '"]</code></p></td>';
            echo '<td><a class="button button-small" href="' . esc_url( $url ) . '">编辑</a></td></tr>';
        }
        if ( ! $profiles ) { echo '<tr><td colspan="5">暂未创建独立表单；原有默认表单仍可正常使用。</td></tr>'; }
        echo '</tbody></table></div>';

        echo '<div class="aikey-panel"><h2>' . ( $editing ? '编辑表单：' . esc_html( $id ) : '创建新表单' ) . '</h2>';
        echo '<form method="post"><input type="hidden" name="aikey_action" value="save_form_profile">';
        wp_nonce_field( 'aikey_save_form_profile' );
        if ( $editing ) {
            echo '<p>表单编号：<strong><code>' . esc_html( $id ) . '</code></strong><input type="hidden" name="form_id" value="' . esc_attr( $id ) . '"></p>';
        } else {
            $this->setting_input( 'form_id', '表单编号（必填）', '', '英文小写字母开头，可用数字、-、_，如 en、fr、ar、en-sales。保存后此编号用于短代码。' );
        }
        $this->setting_input( 'form_label', '后台表单名称', $selected['label'] ?? '', '仅用于后台区分表单，如“英文询盘”“法语联系表单”。' );
        echo '<div class="aikey-field"><label><strong>表单语言</strong></label><select name="form_language">';
        foreach ( Form_Profiles::languages() as $code=>$label ) {
            echo '<option value="' . esc_attr( $code ) . '" ' . selected( $selected['language'] ?? 'en', $code, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select><p class="description">会自动切换姓名、电话、邮箱、留言、校验提示等表单文字。</p></div>';
        $this->setting_input( 'form_company', '地图显示企业名称（可选）', $selected['company'] ?? '', '留空使用企业实体 / Schema 的企业名称。' );
        $this->setting_input( 'form_address', '此表单的地图地址（可选）', $selected['address'] ?? '', '留空使用全站地图地址；不影响 Schema。' );
        $this->setting_input( 'form_heading', '联系表单标题（可选）', $selected['heading'] ?? '', '留空使用所选语言的默认标题。' );
        $this->setting_input( 'form_submit', '提交按钮文字（可选）', $selected['submit'] ?? '', '留空使用所选语言的默认文字。' );
        echo '<p><button class="button button-primary">保存表单配置</button></p></form>';
        if ( $editing ) {
            echo '<form method="post" onsubmit="return confirm(\'删除此表单配置？历史留言不会删除，但使用该编号的短代码将不再显示。\');">';
            wp_nonce_field( 'aikey_delete_form_profile' );
            echo '<input type="hidden" name="aikey_action" value="delete_form_profile"><input type="hidden" name="form_id" value="' . esc_attr( $id ) . '"><button class="button button-link-delete">删除此表单配置</button></form>';
        }
        echo '</div></div>';
    }

    private function save_form_profile(): void {
        $id = sanitize_key( $this->raw( 'form_id' ) );
        if ( ! Form_Profiles::valid_id( $id ) ) {
            $this->notice( 'error', '表单编号无效：须以小写英文字母开头，只允许数字、-、_，长度不超过 40。' );
            $this->go( 'aikey-seo-geo-forms' );
        }
        $settings = Settings::all();
        $forms = Form_Profiles::all();
        $forms[ $id ] = Form_Profiles::normalize( $id, array(
            'language'=>$this->raw( 'form_language' ),
            'label'=>$this->raw( 'form_label' ),
            'company'=>$this->raw( 'form_company' ),
            'address'=>$this->raw( 'form_address' ),
            'heading'=>$this->raw( 'form_heading' ),
            'submit'=>$this->raw( 'form_submit' ),
        ) );
        $settings['map']['forms'] = $forms;
        $ok = Settings::update( $settings );
        $this->notice( $ok ? 'success' : 'error', $ok ? '表单配置已保存。' : '表单配置保存失败。' );
        $this->go( 'aikey-seo-geo-forms' );
    }

    private function delete_form_profile(): void {
        $id = sanitize_key( $this->raw( 'form_id' ) );
        $settings = Settings::all();
        unset( $settings['map']['forms'][ $id ] );
        $ok = Settings::update( $settings );
        $this->notice( $ok ? 'success' : 'error', $ok ? '表单配置已删除，历史留言仍保留。' : '表单配置删除失败。' );
        $this->go( 'aikey-seo-geo-forms' );
    }

    public function contacts(): void {
        $unread = Contact_Repository::unread_count();
        $total = Contact_Repository::total_count();
        Contact_Repository::mark_all_read();
        $rows = Contact_Repository::recent( 300 );
        $this->page_title( '表单信息', '网站联系表单提交后直接保存在本站数据库中，不发送邮件通知。' );
        $this->render_notice();
        echo '<div class="aikey-panel"><p><strong>前台展示：</strong>默认地图 + 表单 <code>[aikey_enterprise_map]</code>；纯表单 <code>[aikey_contact_form]</code>。多语言独立表单使用 <code>[aikey_enterprise_map form="en"]</code> 等。<a href="' . esc_url( admin_url( 'admin.php?page=aikey-seo-geo-forms' ) ) . '">管理多语言表单与短代码</a> · <a href="' . esc_url( admin_url( 'admin.php?page=aikey-seo-geo-settings#aikey-enterprise-map-settings' ) ) . '">设置默认地图</a>。</p></div>';
        echo '<div class="aikey-grid aikey-grid-4">';
        $this->card( '全部表单', (string) $total, '当前数据库中的联系表单数量', 'ok' );
        $this->card( '本次新留言', (string) $unread, '打开本页后自动标记为已查看', $unread > 0 ? 'next' : 'muted' );
        echo '</div>';
        echo '<div class="aikey-panel"><div class="aikey-panel-head"><div><h2>最近提交</h2><p class="description">显示最近 300 条表单信息。电话为必填字段；姓名、电子邮件和留言按访客实际填写内容保存。</p></div></div>';
        echo '<table class="widefat striped"><thead><tr><th>提交时间</th><th>表单</th><th>姓名</th><th>电话</th><th>电子邮件</th><th>留言</th><th>来源页面</th><th>操作</th></tr></thead><tbody>';
        if ( ! $rows ) { echo '<tr><td colspan="8">暂无表单信息。</td></tr>'; }
        foreach ( $rows as $row ) {
            $submitted_at = get_date_from_gmt( (string) $row->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
            echo '<tr><td>' . esc_html( $submitted_at ) . '</td><td>' . esc_html( (string) ( $row->form_id ?: '默认表单' ) ) . '</td><td>' . esc_html( (string) $row->name ) . '</td><td><strong>' . esc_html( (string) $row->phone ) . '</strong></td><td>' . esc_html( (string) $row->email ) . '</td><td style="max-width:380px;white-space:pre-wrap">' . esc_html( (string) $row->message ) . '</td><td>' . ( $row->source_url ? '<a target="_blank" rel="noopener" href="' . esc_url( (string) $row->source_url ) . '">查看页面</a>' : '-' ) . '</td><td><form method="post" onsubmit="return confirm(\'确定删除这条表单信息？\');"><input type="hidden" name="aikey_action" value="delete_contact_message"><input type="hidden" name="contact_id" value="' . esc_attr( (string) $row->id ) . '">'; wp_nonce_field( 'aikey_delete_contact_message' ); echo '<button class="button-link-delete">删除</button></form></td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private function delete_contact_message(): void {
        $id = absint( $_POST['contact_id'] ?? 0 );
        if ( $id > 0 && Contact_Repository::delete( $id ) ) { $this->notice( 'success', '表单信息已删除。' ); }
        else { $this->notice( 'error', '表单信息删除失败。' ); }
        $this->go( 'aikey-seo-geo-contacts' );
    }


    public function optimizer(): void {
        $post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
        $this->page_title( 'SEO / GEO 诊断优化工作台', '统一完成 SEO 与 GEO 扫描、评分、问题诊断、批量安全优化和单页深度优化；所有修改直接同步真实 WordPress 页面。' );
        $this->render_notice();

        if ( $post_id <= 0 ) {
            $search_query = isset( $_GET['page_search'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['page_search'] ) ) ) : '';
            $search_query = function_exists( 'mb_substr' ) ? mb_substr( $search_query, 0, 500, 'UTF-8' ) : substr( $search_query, 0, 500 );
            $search_error = '';
            if ( '' !== $search_query ) {
                $search_result = Optimization_Workbench::search_public_posts( $search_query, 50 );
                if ( is_wp_error( $search_result ) ) {
                    $search_error = $search_result->get_error_message();
                    $posts = array();
                } else {
                    $posts = $search_result;
                }
            } else {
                $posts = get_posts( array(
                    'post_type'              => Utils::public_post_types(),
                    'post_status'            => 'publish',
                    'posts_per_page'         => 100,
                    'orderby'                => 'modified',
                    'order'          => 'DESC',
                ) );
            }

            echo '<div class="aikey-panel aikey-page-search"><div class="aikey-panel-head"><div><h2>页面搜索</h2><p class="description">输入本站前台完整网址、相对路径、页面标题、Slug 或内容 ID。完整网址优先精准定位，不扫描全站 URL。</p></div></div>';
            echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="aikey-page-search-form"><input type="hidden" name="page" value="aikey-seo-geo-optimizer"><input type="search" class="regular-text" name="page_search" maxlength="500" value="' . esc_attr( $search_query ) . '" placeholder="例如：https://example.com/about/ 或 公司简介"><button class="button button-primary">搜索页面</button>';
            if ( '' !== $search_query ) { echo '<a class="button" href="' . esc_url( add_query_arg( 'page', 'aikey-seo-geo-optimizer', admin_url( 'admin.php' ) ) ) . '">清除搜索</a>'; }
            echo '</form>';
            if ( '' !== $search_error ) { echo '<p class="aikey-search-error">' . esc_html( $search_error ) . '</p>'; }
            echo '</div>';

            $seo_sum = 0; $geo_sum = 0; $scored = 0; $problem_pages = 0;
            $rows = array();
            foreach ( $posts as $post ) {
                if ( ! $post instanceof \WP_Post ) { continue; }
                // Calculate live values so the unified list never shows stale SEO/GEO issues after content changes.
                $seo_live = Audit_Module::analyze( $post );
                $geo_live = Scorer::score_post( $post );
                $seo = (int) ( $seo_live['score'] ?? 0 );
                $geo_score = (int) ( $geo_live['score'] ?? 0 );
                $seo_issues = isset( $seo_live['issues'] ) && is_array( $seo_live['issues'] ) ? $seo_live['issues'] : array();
                $geo_issues = isset( $geo_live['issues'] ) && is_array( $geo_live['issues'] ) ? $geo_live['issues'] : array();
                $seo_sum += $seo; $geo_sum += $geo_score; $scored++;
                if ( $seo_issues || $geo_issues ) { $problem_pages++; }
                $rows[] = array( 'post'=>$post, 'seo'=>$seo, 'geo'=>$geo_score, 'seo_issues'=>$seo_issues, 'geo_issues'=>$geo_issues );
            }
            $avg_seo = $scored ? (int) round( $seo_sum / $scored ) : 0;
            $avg_geo = $scored ? (int) round( $geo_sum / $scored ) : 0;

            echo '<div class="aikey-grid aikey-grid-4">';
            $this->card( '' !== $search_query ? '搜索结果' : '当前列表', count( $rows ) . ' 页', '' !== $search_query ? '最多显示 50 个匹配的公开页面' : '最近更新的公开页面', 'muted' );
            $this->card( '平均 SEO', $scored ? $avg_seo . '/100' : '待检测', 'SEO 技术与内容基础评分', $avg_seo >= 80 ? 'ok' : 'next' );
            $this->card( '平均 GEO', $scored ? $avg_geo . '/100' : '待检测', '实体、事实、证据与结构', $avg_geo >= 80 ? 'ok' : 'next' );
            $this->card( '存在问题', $problem_pages . ' 页', 'SEO 或 GEO 仍有待优化项', $problem_pages ? 'next' : 'ok' );
            echo '</div>';

            echo '<div class="aikey-panel aikey-diagnostic-toolbar"><div><h2>统一诊断</h2><p class="description">一次扫描同时刷新 SEO 与 GEO 分数和问题列表，不再分别进入两个诊断页面。</p></div><form method="post">';
            wp_nonce_field( 'aikey_run_diagnostics' );
            echo '<input type="hidden" name="aikey_action" value="run_diagnostics"><button class="button button-primary">扫描最近更新的 300 个页面</button></form></div>';

            echo '<div class="aikey-panel"><div class="aikey-panel-head"><div><h2>SEO / GEO 诊断结果</h2><p class="description">勾选页面可执行低风险批量优化；点击“进入优化”可查看完整问题并修改 SEO、GEO、Schema、摘要、标签、事实、FAQ 等。</p></div></div>';
            echo '<form method="post" id="aikey-unified-bulk-form">';
            wp_nonce_field( 'aikey_bulk_safe_optimize' );
            echo '<input type="hidden" name="aikey_action" value="bulk_safe_optimize"><input type="hidden" name="return_page" value="aikey-seo-geo-optimizer">';
            echo '<table class="widefat striped aikey-unified-diagnostic-table"><thead><tr><th style="width:34px"><input type="checkbox" class="aikey-select-all" data-target=".aikey-unified-select"></th><th>页面</th><th>类型</th><th>SEO</th><th>GEO</th><th>SEO 主要问题</th><th>GEO 主要问题</th><th style="width:88px"></th></tr></thead><tbody>';
            if ( ! $rows ) {
                echo '<tr><td colspan="8">' . esc_html( '' !== $search_query ? '没有找到匹配的公开页面。' : '暂无可诊断的公开页面。' ) . '</td></tr>';
            } else {
                foreach ( $rows as $row ) {
                    $post = $row['post'];
                    echo '<tr>';
                    echo '<td><input class="aikey-unified-select" type="checkbox" name="post_ids[]" value="' . esc_attr( (string) $post->ID ) . '"></td>';
                    $row_url = '' !== $search_query ? get_permalink( $post ) : '';
                    echo '<td><strong>' . esc_html( get_the_title( $post ) ) . '</strong><div class="aikey-table-sub">ID ' . esc_html( (string) $post->ID );
                    if ( $row_url ) { echo ' · <a target="_blank" rel="noopener" href="' . esc_url( $row_url ) . '">' . esc_html( $row_url ) . '</a>'; }
                    echo '</div></td>';
                    echo '<td><code>' . esc_html( $post->post_type ) . '</code></td>';
                    echo '<td>' . $this->score_badge( (int) $row['seo'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- score_badge escapes CSS class and text.
                    echo '<td>' . $this->score_badge( (int) $row['geo'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- score_badge escapes CSS class and text.
                    echo '<td class="aikey-issue-cell">' . esc_html( implode( '；', array_slice( (array) $row['seo_issues'], 0, 2 ) ) ?: '暂无明显问题' ) . '</td>';
                    echo '<td class="aikey-issue-cell">' . esc_html( implode( '；', array_slice( (array) $row['geo_issues'], 0, 2 ) ) ?: '暂无明显问题' ) . '</td>';
                    echo '<td><a class="button button-primary button-small" href="' . esc_url( $this->optimizer_url( $post->ID ) ) . '">进入优化</a></td>';
                    echo '</tr>';
                }
            }
            echo '</tbody></table><div class="aikey-workbench-bulk"><button class="button button-primary">一键安全优化所选页面</button><span class="description">最多处理 50 个页面；只补空白低风险字段，不重写正文、不修改 URL、不自动创建 301。</span></div></form></div></div>';
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post_id ) || ! in_array( $post->post_type, Utils::public_post_types(), true ) ) {
            echo '<div class="notice notice-error"><p>找不到该页面，或你没有编辑权限。</p></div></div>'; return;
        }
        $analysis = Optimization_Workbench::analyze( $post );
        $current = Optimization_Workbench::snapshot( $post );
        $seo_score=(int)($analysis['seo']['score']??0); $geo_score=(int)($analysis['geo']['score']??0);
        $view_url=get_permalink($post); $edit_url=get_edit_post_link($post_id,'');
        echo '<div class="aikey-workbench-head"><div><h2>' . esc_html( get_the_title( $post ) ) . '</h2><p><code>' . esc_html( $post->post_type ) . '</code> · ID ' . esc_html( (string)$post_id ) . '</p></div><div class="aikey-workbench-links">' . ( $view_url ? '<a class="button" target="_blank" rel="noopener" href="' . esc_url($view_url) . '">查看前台</a>' : '' ) . ( $edit_url ? '<a class="button" href="' . esc_url($edit_url) . '">打开原编辑器</a>' : '' ) . '</div></div>';
        echo '<div class="aikey-grid aikey-grid-4">'; $this->card('SEO评分',$seo_score.'/100','保存后自动重新计算',$seo_score>=80?'ok':'next'); $this->card('GEO评分',$geo_score.'/100','实体、事实、证据与结构',$geo_score>=80?'ok':'next'); $this->card('发布状态',$post->post_status,'优化直接同步原页面','ok'); $this->card('最近修改',get_the_modified_date('Y-m-d H:i',$post),'WordPress 页面数据','muted'); echo '</div>';

        echo '<div class="aikey-workbench-actions">';
        echo '<form method="post">'; wp_nonce_field('aikey_workbench_safe'); echo '<input type="hidden" name="aikey_action" value="workbench_safe"><input type="hidden" name="post_id" value="' . esc_attr((string)$post_id) . '"><button class="button button-primary">一键安全优化</button></form>';
        echo '<form method="post">'; wp_nonce_field('aikey_workbench_rescore'); echo '<input type="hidden" name="aikey_action" value="workbench_rescore"><input type="hidden" name="post_id" value="' . esc_attr((string)$post_id) . '"><button class="button">重新检测</button></form>';
        echo '<form method="post">'; wp_nonce_field('aikey_workbench_ai_generate'); echo '<input type="hidden" name="aikey_action" value="workbench_ai_generate"><input type="hidden" name="post_id" value="' . esc_attr((string)$post_id) . '"><button class="button button-secondary"' . ( ''===Settings::api_key()?' disabled':'' ) . '>AI 生成优化建议</button></form>';
        if ( ''===Settings::api_key() ) { echo '<span class="description">DeepSeek API Key 未配置，请先到“基础设置 → DeepSeek AI”配置。</span>'; }
        echo '</div>';

        echo '<div class="aikey-workbench-issues"><div class="aikey-panel"><h2>SEO 待优化问题</h2>' . $this->issue_list_html( (array)($analysis['seo']['issues']??array()) ) . '</div><div class="aikey-panel"><h2>GEO 待优化问题</h2>' . $this->issue_list_html( (array)($analysis['geo']['issues']??array()) ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- issue_list_html escapes its dynamic items.

        echo '<form method="post">'; wp_nonce_field('aikey_workbench_save'); echo '<input type="hidden" name="aikey_action" value="workbench_save"><input type="hidden" name="post_id" value="' . esc_attr((string)$post_id) . '">';
        echo '<div class="aikey-workbench-columns">';
        echo '<div class="aikey-panel"><h2>SEO / WordPress 内容字段</h2>';
        $this->wb_input('wb_seo_title','SEO 标题',(string)$current['seo_title'],'留空时继续使用全站标题模板。');
        $this->wb_textarea('wb_description','Meta 描述',(string)$current['description'],'建议 60–180 个中文字符。',3);
        $this->wb_input('wb_focus_keyword','核心关键词',(string)$current['focus_keyword'],'只用于内部分析，不输出 meta keywords。');
        $this->wb_input('wb_canonical','Canonical URL',(string)$current['canonical'],'留空表示使用 WordPress 默认规范 URL。');
        echo '<div class="aikey-field-grid"><div class="aikey-field"><label><strong>索引</strong></label><select name="wb_robots_index"><option value="default" ' . selected($current['robots_index'],'default',false) . '>默认</option><option value="index" ' . selected($current['robots_index'],'index',false) . '>Index</option><option value="noindex" ' . selected($current['robots_index'],'noindex',false) . '>Noindex</option></select></div><div class="aikey-field"><label><strong>跟踪链接</strong></label><select name="wb_robots_follow"><option value="default" ' . selected($current['robots_follow'],'default',false) . '>默认</option><option value="follow" ' . selected($current['robots_follow'],'follow',false) . '>Follow</option><option value="nofollow" ' . selected($current['robots_follow'],'nofollow',false) . '>Nofollow</option></select></div></div>';
        echo '<div class="aikey-field"><label><strong>Schema 类型</strong></label><select name="wb_schema_type">'; foreach(array(''=>'自动/未指定','WebPage'=>'WebPage','Article'=>'Article','Product'=>'Product','Service'=>'Service','AboutPage'=>'AboutPage','ContactPage'=>'ContactPage') as $v=>$label){echo '<option value="'.esc_attr($v).'" '.selected($current['schema_type'],$v,false).'>'.esc_html($label).'</option>';} echo '</select></div>';
        if ( post_type_supports($post->post_type,'excerpt') ) { $this->wb_textarea('wb_excerpt','WordPress 摘要',(string)$current['excerpt'],'直接同步 WordPress 原生摘要。',3); }
        if ( is_object_in_taxonomy($post->post_type,'post_tag') ) { $this->wb_input('wb_tags','WordPress 标签',implode('，',(array)$current['tags']),'用逗号分隔；保存后直接同步原生标签。'); }
        echo '</div>';
        echo '<div class="aikey-panel"><h2>GEO 实体 / 可引用性</h2>';
        $this->wb_input('wb_entity','核心实体',(string)$current['entity'],'页面最重要的企业、产品、服务或主题实体。');
        echo '<div class="aikey-field"><label><strong>实体类型</strong></label><select name="wb_entity_type">'; foreach(array(''=>'未指定','Organization'=>'Organization','Product'=>'Product','Service'=>'Service','Person'=>'Person','Place'=>'Place','Article'=>'Article') as $v=>$label){echo '<option value="'.esc_attr($v).'" '.selected($current['entity_type'],$v,false).'>'.esc_html($label).'</option>';} echo '</select></div>';
        $this->wb_textarea('wb_facts','核心事实（每行一条）',(string)$current['facts'],'只填写可由页面或真实资料验证的事实。',6);
        $this->wb_textarea('wb_faq','FAQ（每行：问题||答案）',(string)$current['faq'],'保存后可用于 FAQ Schema。',7);
        $this->wb_textarea('wb_sources','来源 / 证据 URL（每行一个）',(string)$current['sources'],'只接受 http/https URL；用于 GEO 信任与证据。',5);
        echo '</div></div><p><button class="button button-primary button-large">保存并同步到原页面</button></p></form>';

        $bundle = Optimization_Workbench::ai_bundle( $post_id, get_current_user_id() );
        if ( is_array($bundle) && isset($bundle['suggestions']) && is_array($bundle['suggestions']) ) { $this->render_ai_workbench( $post, $current, $bundle ); }
        echo '</div>';
    }

    private function workbench_save(): void {
        $post_id=absint($_POST['post_id']??0); $input=wp_unslash($_POST);
        $result=Optimization_Workbench::save_manual($post_id,is_array($input)?$input:array());
        if(is_wp_error($result)){$this->notice('error',$result->get_error_message());}else{$changed=(array)($result['changed']??array());$this->notice('success',$changed?'已同步：'.implode('、',$changed).'；并重新计算 SEO/GEO 分数。':'没有检测到字段变化，评分已刷新。');}
        $this->go_optimizer($post_id);
    }
    private function workbench_safe(): void {
        $post_id=absint($_POST['post_id']??0);$result=Optimization_Workbench::safe_optimize($post_id,true);
        if(is_wp_error($result)){$this->notice('error',$result->get_error_message());}else{$changed=(array)($result['changed']??array());$this->notice('success',$changed?'安全优化完成：'.implode('、',$changed).'。':'当前没有可安全自动补齐的空白字段；评分已重新计算。');}
        $this->go_optimizer($post_id);
    }
    private function workbench_rescore(): void { $post_id=absint($_POST['post_id']??0);Optimization_Workbench::rescore($post_id);$this->notice('success','SEO / GEO 已重新检测。');$this->go_optimizer($post_id); }
    private function workbench_ai_generate(): void {
        $post_id=absint($_POST['post_id']??0);$result=Optimization_Workbench::generate_ai($post_id,get_current_user_id());
        if(is_wp_error($result)){$this->notice('error',$result->get_error_message());}else{$this->notice('success','AI 优化建议已生成。请对照当前内容，勾选需要应用的项目。');}
        $this->go_optimizer($post_id);
    }
    private function workbench_ai_apply(): void {
        $post_id=absint($_POST['post_id']??0);$raw=$_POST['apply_fields']??array();$fields=is_array($raw)?array_map('sanitize_key',wp_unslash($raw)):array();$result=Optimization_Workbench::apply_ai($post_id,get_current_user_id(),$fields);
        if(is_wp_error($result)){$this->notice('error',$result->get_error_message());}else{$changed=(array)($result['changed']??array());$this->notice('success',$changed?'AI 建议已应用并同步：'.implode('、',$changed).'。':'没有产生实际变化。');}
        $this->go_optimizer($post_id);
    }
    private function bulk_safe_optimize(): void {
        $raw=$_POST['post_ids']??array();$ids=is_array($raw)?array_slice(array_values(array_unique(array_map('absint',wp_unslash($raw)))),0,50):array();$return=sanitize_key((string)($_POST['return_page']??'aikey-seo-geo-optimizer'));
        if ( in_array( $return, array( 'aikey-seo-geo-audit','aikey-seo-geo-geo' ), true ) ) { $return = 'aikey-seo-geo-optimizer'; }
        if(!$ids){$this->notice('warning','请先勾选至少一个页面。');$this->go($return);}
        $processed=0;$changed_count=0;$notify=array();
        foreach($ids as $id){if($id<=0)continue;$r=Optimization_Workbench::safe_optimize($id,false);if(is_wp_error($r))continue;$processed++;if(!empty($r['changed'])){$changed_count++;$notify[]=$id;}}
        if($notify){Optimization_Workbench::queue_notify($notify);}
        $this->notice('success','已处理 '.$processed.' 个页面，其中 '.$changed_count.' 个页面补齐了低风险字段，并重新评分。');$this->go($return);
    }

    private function render_ai_workbench( \WP_Post $post, array $current, array $bundle ): void {
        $s=(array)$bundle['suggestions'];
        echo '<div class="aikey-panel aikey-ai-compare"><div class="aikey-panel-head"><div><h2>AI 建议：修改前后对比</h2><p class="description">AI 只依据当前正文生成建议。勾选后才会写回原页面；H2/H3 只转换原文已有标题式段落，不新增标题文字。</p></div><span class="aikey-chip">建议有效期约2小时</span></div>';
        echo '<form method="post">';wp_nonce_field('aikey_workbench_ai_apply');echo '<input type="hidden" name="aikey_action" value="workbench_ai_apply"><input type="hidden" name="post_id" value="'.esc_attr((string)$post->ID).'">';
        echo '<table class="widefat striped aikey-compare-table"><thead><tr><th style="width:42px">应用</th><th style="width:150px">项目</th><th>当前</th><th>AI 建议</th></tr></thead><tbody>';
        $rows=array(
            'seo_title'=>array('SEO 标题',(string)$current['seo_title'],(string)($s['seo_title']??'')),
            'description'=>array('Meta 描述',(string)$current['description'],(string)($s['description']??'')),
            'focus_keyword'=>array('核心关键词',(string)$current['focus_keyword'],(string)($s['focus_keyword']??'')),
            'schema_type'=>array('Schema',(string)$current['schema_type'],(string)($s['schema_type']??'')),
            'entity'=>array('GEO 核心实体',(string)$current['entity'],(string)($s['entity']??'')),
            'entity_type'=>array('实体类型',(string)$current['entity_type'],(string)($s['entity_type']??'')),
        );
        if(post_type_supports($post->post_type,'excerpt')){$rows['excerpt']=array('WordPress 摘要',(string)$current['excerpt'],(string)($s['excerpt']??''));}
        if(is_object_in_taxonomy($post->post_type,'post_tag')){$rows['tags']=array('WordPress 标签',implode('，',(array)$current['tags']),implode('，',(array)($s['tags']??array())));}
        $rows['facts']=array('核心事实',(string)$current['facts'],implode("\n",(array)($s['facts']??array())));
        $faq_ai=array();foreach((array)($s['faq']??array()) as $r){if(is_array($r)&&isset($r['q'],$r['a'])){$faq_ai[]=$r['q'].'||'.$r['a'];}}$rows['faq']=array('FAQ',(string)$current['faq'],implode("\n",$faq_ai));
        foreach($rows as $key=>$row){$suggest=(string)$row[2];$disabled=''===trim($suggest);echo '<tr><td><input type="checkbox" name="apply_fields[]" value="'.esc_attr($key).'" '.($disabled?'disabled':'').'></td><td><strong>'.esc_html($row[0]).'</strong></td><td><div class="aikey-compare-value">'.nl2br(esc_html(''!==(string)$row[1]?(string)$row[1]:'—')).'</div></td><td><div class="aikey-compare-value aikey-compare-new">'.nl2br(esc_html(''!==trim($suggest)?$suggest:'无建议')).'</div></td></tr>';}
        $headings=(array)($s['heading_levels']??array());echo '<tr><td><input type="checkbox" name="apply_fields[]" value="headings" '.(!$headings?'disabled':'').'></td><td><strong>原文 H2/H3</strong></td><td>保持当前层级</td><td>';
        if($headings){foreach($headings as $h){if(is_array($h)){echo '<span class="aikey-chip">H'.esc_html((string)($h['level']??2)).' '.esc_html((string)($h['original_text']??'')).'</span> ';}}}else{echo '无需要转换的现有标题式段落';}echo '</td></tr></tbody></table>';
        if(!empty($s['notes'])){echo '<div class="aikey-ai-notes"><strong>AI 补充建议：</strong><ul>';foreach((array)$s['notes'] as $note){echo '<li>'.esc_html((string)$note).'</li>';}echo '</ul></div>';}
        echo '<p><button class="button button-primary button-large">应用勾选的 AI 建议并重新评分</button></p></form></div>';
    }

    private function optimizer_url( int $post_id ): string { return add_query_arg(array('page'=>'aikey-seo-geo-optimizer','post'=>$post_id),admin_url('admin.php')); }
    private function go_optimizer( int $post_id ): void { wp_safe_redirect($this->optimizer_url($post_id)); exit; }
    private function issue_list_html( array $issues ): string { if(!$issues){return '<p class="aikey-success-text">✓ 当前没有检测到明显问题。</p>';} $html='<ul class="aikey-workbench-issue-list">';foreach($issues as $issue){$html.='<li>'.esc_html((string)$issue).'</li>';}$html.='</ul>';return $html; }
    private function wb_input( string $name,string $label,string $value,string $help='' ): void { echo '<div class="aikey-field"><label><strong>'.esc_html($label).'</strong></label><input class="large-text" type="text" name="'.esc_attr($name).'" value="'.esc_attr($value).'">';if($help){echo '<p class="description">'.esc_html($help).'</p>';}echo '</div>'; }
    private function wb_textarea( string $name,string $label,string $value,string $help='',int $rows=4 ): void { echo '<div class="aikey-field"><label><strong>'.esc_html($label).'</strong></label><textarea class="large-text" rows="'.esc_attr((string)$rows).'" name="'.esc_attr($name).'">'.esc_textarea($value).'</textarea>';if($help){echo '<p class="description">'.esc_html($help).'</p>';}echo '</div>'; }

    public function automation(): void {
        $s = Settings::all();
        $auto = isset( $s['automation'] ) && is_array( $s['automation'] ) ? $s['automation'] : array();
        $mode = (string) ( $auto['mode'] ?? 'recommended' );
        $last = (string) get_option( 'aikey_seo_geo_last_maintenance', '' );
        $this->page_title( '智能自动优化', '安装后自动处理低风险 SEO/GEO 基础工作；高风险正文改写、删除内容和自动 301 始终需要人工确认。' );
        $this->render_notice(); ?>
        <div class="aikey-grid aikey-grid-4">
            <?php $this->card( '当前模式', 'conservative' === $mode ? '保守' : ( 'full' === $mode ? '增强自动' : '推荐' ), '只对空字段/安全项目执行自动化', 'ok' ); ?>
            <?php $this->card( '发布即评分', ! empty( $auto['auto_score'] ) ? '已启用' : '已关闭', '自动更新 SEO/GEO 本地评分', ! empty( $auto['auto_score'] ) ? 'ok' : 'muted' ); ?>
            <?php $this->card( 'IndexNow', ! empty( $s['indexnow']['auto_submit'] ) ? '自动' : '手动', '状态摘要；配置统一在提交中心', ! empty( $s['indexnow']['auto_submit'] ) ? 'ok' : 'next' ); ?>
            <?php $this->card( '最近巡检', $last ? gmdate( 'Y-m-d H:i', strtotime( $last ) ) . ' UTC' : '尚未运行', '每日维护由 WP-Cron 执行', $last ? 'ok' : 'next' ); ?>
        </div>
        <form method="post"><input type="hidden" name="aikey_action" value="save_automation"><?php wp_nonce_field( 'aikey_save_automation' ); ?>
            <div class="aikey-panel"><h2>自动化等级</h2>
                <div class="aikey-field"><label><strong>模式</strong></label><select name="automation_mode" class="regular-text">
                    <option value="conservative" <?php selected( $mode, 'conservative' ); ?>>保守模式</option>
                    <option value="recommended" <?php selected( $mode, 'recommended' ); ?>>推荐模式</option>
                    <option value="full" <?php selected( $mode, 'full' ); ?>>增强自动模式</option>
                </select><p class="description">保守/推荐模式不会自动填写 GEO 实体；增强自动模式也只补空字段，不覆盖人工内容。任何模式都不会自动重写正文、自动删除正文或自动创建 301。</p></div>
            </div>
            <div class="aikey-panel"><h2>发布与保存自动处理</h2>
                <label><input type="checkbox" name="optimize_on_save" value="1" <?php checked( ! empty( $auto['optimize_on_save'] ) ); ?>> 发布/更新公开页面时运行安全自动优化</label><br>
                <label><input type="checkbox" name="auto_score" value="1" <?php checked( ! empty( $auto['auto_score'] ) ); ?>> 自动重新计算 SEO 与 GEO 分数</label><br>
                <label><input type="checkbox" name="auto_schema" value="1" <?php checked( ! empty( $auto['auto_schema'] ) ); ?>> 空白时自动判断基础 Schema 类型</label><br>
                <label><input type="checkbox" name="fill_empty_entity" value="1" <?php checked( ! empty( $auto['fill_empty_entity'] ) ); ?>> 增强自动模式下，用核心关键词/标题补空白核心实体</label>
            </div>
            <div class="aikey-panel"><h2>每日维护</h2>
                <label><input type="checkbox" name="daily_maintenance" value="1" <?php checked( ! empty( $auto['daily_maintenance'] ) ); ?>> 每日自动轮换巡检公开页面</label>
                <div class="aikey-field"><label><strong>每日扫描上限</strong></label><input type="number" min="20" max="500" name="daily_scan_limit" value="<?php echo esc_attr( (string) ( $auto['daily_scan_limit'] ?? 200 ) ); ?>"></div>
            </div>
            <p><button class="button button-primary button-large">保存自动优化设置</button></p>
        </form>
        <div class="aikey-panel"><h2>立即运行一次</h2><form method="post" class="aikey-inline-form"><?php wp_nonce_field( 'aikey_run_automation' ); ?><input type="hidden" name="aikey_action" value="run_automation"><label>扫描页面 <input type="number" min="20" max="500" name="automation_limit" value="200"></label><button class="button button-primary">立即巡检并刷新评分</button></form></div>
        </div><?php
    }

    public function submissions(): void {
        $s = Settings::all();
        $count = Sitemap_Module::published_indexable_count();
        $daily_enabled = ! empty( $s['submission']['daily_incremental'] );
        $daily_limit = min( 500, max( 1, (int) ( $s['submission']['daily_limit'] ?? 19 ) ) );
        $this->page_title( '搜索 / AI 引擎提交中心', '百度、IndexNow、每日增量与手动主动提交统一在这里配置和执行；Sitemap / robots 已独立集中管理。' );
        $this->render_notice();
        if ( ! (bool) get_option( 'blog_public', true ) ) {
            echo '<div class="aikey-panel aikey-warning-panel"><h2>提交已暂停</h2><p>WordPress 当前设置为建议搜索引擎不索引本站。Aikeyboost不会在此状态下向 IndexNow 或百度主动提交 URL。</p></div>';
        }
        ?>
        <div class="aikey-grid aikey-grid-4">
            <?php $this->card( '可索引内容', (string) $count, '公开文章/页面/自定义内容，未设置 noindex', 'ok' ); ?>
            <?php $indexnow_realtime = ! empty( $s['indexnow']['auto_submit'] ); $indexnow_state = $indexnow_realtime && $daily_enabled ? '实时 + 每日增量' : ( $daily_enabled ? '每日增量' : ( $indexnow_realtime ? '实时自动' : '手动' ) ); $this->card( 'IndexNow', $indexnow_state, 'Bing/Copilot 等参与或依赖 Bing 发现的场景', $indexnow_realtime || $daily_enabled ? 'ok' : 'next' ); ?>
            <?php $baidu_configured = ! empty( $s['baidu']['site'] ) && ! empty( $s['baidu']['token'] ); $baidu_state = $baidu_configured ? ( Baidu_Submitter::quota_paused_today() ? '今日配额已用完' : ( $daily_enabled ? '已配置 + 每日增量' : '已配置' ) ) : '待配置'; $this->card( '百度 API', $baidu_state, '百度搜索 + 百度 AI 网页发现入口', $baidu_configured && ! Baidu_Submitter::quota_paused_today() ? 'ok' : 'next' ); ?>
            <?php $this->card( '每日增量', $daily_enabled ? '已启用' : '未启用', '默认每日最多 ' . $daily_limit . ' 条候选 URL', $daily_enabled ? 'ok' : 'next' ); ?>
        </div>
        <?php
        $daily_next = Daily_Submission::next_run();
        $daily_last = Daily_Submission::last_run();
        $daily_stats = Daily_Submission::last_stats();
        ?>
        <div class="aikey-panel"><div class="aikey-panel-head"><div><h2>自动提交设置</h2><p class="description">百度 API、IndexNow 实时通知和每日增量只在提交中心维护，避免在“基础设置”或“中国引擎”重复配置。</p></div></div>
            <form method="post">
                <input type="hidden" name="aikey_action" value="save_submission_settings">
                <?php wp_nonce_field( 'aikey_save_submission_settings' ); ?>
                <div class="aikey-field-grid">
                    <div class="aikey-field"><label><strong>百度已验证站点 URL</strong></label><input class="regular-text" type="url" name="baidu_site" value="<?php echo esc_attr( (string) ( $s['baidu']['site'] ?? '' ) ); ?>" placeholder="https://example.com"><p class="description">百度普通收录 API 的站点；插件提交时会按百度接口要求处理域名。</p></div>
                    <div class="aikey-field"><label><strong>百度 API Token</strong></label><input class="regular-text" type="password" name="baidu_token" value="" placeholder="留空保持现有 Token"><p class="description">当前状态：<?php echo esc_html( ! empty( $s['baidu']['token'] ) ? '已配置' : '未配置' ); ?> <a target="_blank" rel="noopener noreferrer" href="https://ziyuan.baidu.com/linksubmit/index">获取 Token</a></p></div>
                </div>
                <div class="aikey-field"><strong>百度自动提交</strong><br><label><input type="checkbox" name="baidu_auto_submit" value="1" <?php checked( ! empty( $s['baidu']['auto_submit'] ) ); ?>> 新页面首次发布时自动提交</label><br><label><input type="checkbox" name="baidu_auto_submit_updates" value="1" <?php checked( ! empty( $s['baidu']['auto_submit_updates'] ) ); ?>> 已发布页面内容更新后自动提交</label><p class="description">百度通道单次硬上限始终为 19 条，避免其他入口意外突破批量保护。</p></div>
                <div class="aikey-field"><strong>IndexNow</strong><br><label><input type="checkbox" name="indexnow_auto_submit" value="1" <?php checked( $indexnow_realtime ); ?>> 新增、更新或删除公开页面时实时提交 IndexNow</label><p class="description">用于支持 IndexNow 的搜索体系；Key 由插件安全管理。</p></div>
                <div class="aikey-field"><strong>每日增量补偿</strong><br><label><input type="checkbox" name="daily_incremental" value="1" <?php checked( $daily_enabled ); ?>> 每天自动提交新增/更新且尚未确认成功的 URL</label> <label style="margin-left:16px">每日最多 <input type="number" min="1" max="500" name="daily_submission_limit" value="<?php echo esc_attr( (string) $daily_limit ); ?>"> 条候选 URL</label><p class="description">默认 19。百度仍最多 19 条；IndexNow 可处理当前增量候选。</p></div>
                <p><button class="button button-primary button-large">保存提交设置</button></p>
            </form>
            <div class="aikey-grid aikey-grid-3">
                <div><strong>上次增量运行</strong><p><?php echo esc_html( $daily_last ?: '尚未运行' ); ?></p></div>
                <div><strong>下次计划</strong><p><?php echo esc_html( $daily_next ? wp_date( 'Y-m-d H:i', $daily_next ) : '未计划' ); ?></p></div>
                <div><strong>自动通道</strong><p>百度普通收录 API + IndexNow</p></div>
            </div>
            <?php if ( $daily_stats ) : ?><p class="description">最近结果：IndexNow <?php echo esc_html( (string) ( $daily_stats['indexnow']['message'] ?? '' ) ); ?>；百度 <?php echo esc_html( (string) ( $daily_stats['baidu']['message'] ?? '' ) ); ?></p><?php endif; ?>
            <form method="post" class="aikey-inline-form"><?php wp_nonce_field( 'aikey_run_daily_submission' ); ?><input type="hidden" name="aikey_action" value="run_daily_submission"><button class="button">立即运行一次增量任务</button></form>
        </div>
        <div class="aikey-panel aikey-submit-all-panel"><div><h2>一键提交所有可自动通道</h2><p class="description">当前公开、稳定且适合普通企业网页自动化的通道为百度普通收录 API 与 IndexNow。百度通道单次最多取前 19 条；IndexNow 使用你设置的数量。其他平台若只公开 Sitemap/站长工具，Aikeyboost不会冒充或逆向调用私有接口。</p></div><form method="post" class="aikey-inline-submit-all"><?php wp_nonce_field( 'aikey_submit_all_supported' ); ?><input type="hidden" name="aikey_action" value="submit_all_supported"><label>最近更新 URL <input type="number" min="1" max="200" name="submit_all_limit" value="19"></label><button class="button button-primary">一键主动提交</button></form></div>

        <?php
        $manual_urls = Sitemap_Module::manual_submission_urls( 500 );
        $manual_default = array_slice( $manual_urls, 0, min( 100, count( $manual_urls ) ) );
        ?>
        <div class="aikey-panel aikey-url-list-tool">
            <div class="aikey-panel-head">
                <div><h2>页面 URL 链接列表</h2><p class="description">用于截图这类“只接受页面链接、不接受 Sitemap 文件”的站长平台。生成结果是一行一个真实页面 URL，不是 <code>.xml</code> 文件地址。</p></div>
                <div class="aikey-url-list-controls"><label>生成数量 <input id="aikey-url-list-limit" type="number" min="1" max="500" value="100"></label><button type="button" class="button button-primary" data-aikey-url-list-generate>一键生成链接</button></div>
            </div>
            <textarea id="aikey-manual-url-list" class="large-text code aikey-url-list-textarea" rows="12" readonly><?php echo esc_textarea( implode( "\n", $manual_default ) ); ?></textarea>
            <div class="aikey-url-list-footer">
                <span class="description">当前已生成 <strong id="aikey-url-list-count"><?php echo esc_html( (string) count( $manual_default ) ); ?></strong> 条；最多一次生成 500 条可索引 URL。</span>
                <div class="aikey-sitemap-actions"><button type="button" class="button button-primary" data-aikey-copy="#aikey-manual-url-list">一键复制全部链接</button><button type="button" class="button" data-aikey-copy-first="#aikey-manual-url-list">复制第一条</button></div>
            </div>
            <script>window.AIKEYManualSubmissionUrls = <?php echo wp_json_encode( array_values( $manual_urls ), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;</script>
        </div>

        <h2 class="aikey-section-title">搜索引擎提交板块</h2>
        <div class="aikey-engine-submit-grid">
            <?php foreach ( Submission_Registry::search_engines() as $id => $platform ) { $this->submission_card( $id, $platform, $s ); } ?>
        </div>

        <h2 class="aikey-section-title">AI 搜索 / 回答引擎发现入口</h2>
        <div class="aikey-engine-submit-grid aikey-engine-submit-grid-ai">
            <?php foreach ( Submission_Registry::ai_engines() as $id => $platform ) { $this->submission_card( $id, $platform, $s ); } ?>
        </div>

        <div class="aikey-panel"><div class="aikey-panel-head"><h2>最近提交日志</h2><form method="post"><?php wp_nonce_field( 'aikey_clear_submission_logs' ); ?><input type="hidden" name="aikey_action" value="clear_submission_logs"><button class="button">清空日志</button></form></div>
            <table class="widefat striped"><thead><tr><th>时间 UTC</th><th>通道</th><th>数量</th><th>HTTP</th><th>状态</th><th>说明</th><th>示例 URL</th></tr></thead><tbody>
            <?php $logs = Submission_Log::recent( 80 ); if ( ! $logs ) : ?><tr><td colspan="7">暂无提交记录。</td></tr><?php else : foreach ( $logs as $row ) : ?><tr><td><?php echo esc_html( (string) $row->created_at ); ?></td><td><?php echo esc_html( 'baidu' === $row->channel ? '百度' : ( 'indexnow' === $row->channel ? 'IndexNow' : (string) $row->channel ) ); ?></td><td><?php echo esc_html( (string) $row->item_count ); ?></td><td><?php echo esc_html( (string) $row->status_code ); ?></td><td><?php echo ! empty( $row->success ) ? '<span class="aikey-chip">成功</span>' : '<span class="aikey-score-badge bad">失败</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td><?php echo esc_html( (string) $row->message ); ?></td><td><code><?php echo esc_html( (string) $row->sample_url ); ?></code></td></tr><?php endforeach; endif; ?>
            </tbody></table>
        </div></div><?php
    }

    private function submission_card( string $id, array $platform, array $settings ): void {
        // Resolve delegated discovery once in the registry so Baidu AI/Copilot can reuse
        // the real Baidu/IndexNow action without creating fake AI-specific endpoints.
        $resolved = Submission_Registry::for_engine( $id );
        if ( $resolved ) { $platform = $resolved; }
        $mode = (string) ( $platform['mode'] ?? 'none' );
        $channel = (string) ( $platform['plugin_channel'] ?? '' );
        $sitemap_type = (string) ( $platform['sitemap'] ?? '' );
        $links = (array) ( $platform['links'] ?? array() );
        $status_class = in_array( $mode, array( 'api','indexnow' ), true ) ? 'is-direct' : ( in_array( $mode, array( 'delegated','crawler' ), true ) ? 'is-indirect' : 'is-manual' );
        echo '<section class="aikey-engine-submit-card ' . esc_attr( $status_class ) . '">';
        echo '<div class="aikey-engine-submit-head"><div><span class="aikey-engine-submit-mode">' . esc_html( Submission_Registry::mode_label( $mode ) ) . '</span><h3>' . esc_html( (string) ( $platform['label'] ?? $id ) ) . '</h3></div><span class="aikey-engine-submit-status">' . esc_html( (string) ( $platform['status'] ?? '' ) ) . '</span></div>';
        echo '<p>' . esc_html( (string) ( $platform['description'] ?? '' ) ) . '</p>';
        if ( 'standard' === $sitemap_type || 'baidu' === $sitemap_type ) {
            echo '<div class="aikey-engine-submit-inline-status">发现方式：<strong>动态 Sitemap</strong>（地址与健康状态统一在“Sitemap / robots”页面维护）</div>';
        }
        if ( 'baidu' === $channel ) {
            $configured = ! empty( $settings['baidu']['site'] ) && ! empty( $settings['baidu']['token'] );
            $plugin_status = $configured ? ( Baidu_Submitter::quota_paused_today() ? '今日配额已用完，次日自动恢复' : ( ! empty( $settings['submission']['daily_incremental'] ) ? '已配置；每日增量已启用' : '已配置' ) ) : '待配置 API Token';
            echo '<div class="aikey-engine-submit-inline-status">插件状态：<strong>' . esc_html( $plugin_status ) . '</strong></div>';
            echo '<form method="post" class="aikey-engine-submit-form">'; wp_nonce_field( 'aikey_baidu_submit' ); echo '<input type="hidden" name="aikey_action" value="baidu_submit"><input type="number" min="1" max="19" name="baidu_limit" value="19"><button class="button button-primary"' . ( $configured ? '' : ' disabled' ) . '>立即提交百度</button></form>';
        } elseif ( 'indexnow' === $channel ) {
            $realtime = ! empty( $settings['indexnow']['auto_submit'] );
            $daily = ! empty( $settings['submission']['daily_incremental'] );
            $plugin_status = $realtime && $daily ? '发布/更新即时提交 + 每日增量补偿' : ( $daily ? '每日增量已启用' : ( $realtime ? '发布/更新即时提交已启用' : '当前为手动提交' ) );
            echo '<div class="aikey-engine-submit-inline-status">插件状态：<strong>' . esc_html( $plugin_status ) . '</strong></div>';
            echo '<form method="post" class="aikey-engine-submit-form">'; wp_nonce_field( 'aikey_indexnow_submit' ); echo '<input type="hidden" name="aikey_action" value="indexnow_submit"><input type="hidden" name="return_page" value="aikey-seo-geo-submit"><input type="number" min="1" max="1000" name="indexnow_limit" value="100"><button class="button button-primary">立即提交 IndexNow</button></form>';
        }
        $daily_on = ! empty( $settings['submission']['daily_incremental'] );
        if ( 'baidu' === $channel ) {
            $daily_text = Baidu_Submitter::quota_paused_today() ? '今日百度配额已用完：次日自动重试' : ( $daily_on ? '每日自动：仅提交新增/更新且尚未成功提交的 URL' : '每日自动：未启用' );
        } elseif ( 'indexnow' === $channel ) {
            $daily_text = $daily_on ? '每日自动：增量补偿提交（发布/更新即时通知仍保留）' : '每日自动：未启用' ;
        } elseif ( 'standard' === $sitemap_type || 'baidu' === $sitemap_type ) {
            $daily_text = '每日发现：Sitemap 为动态生成，内容变化会自动反映，无公开 API 时无需伪造推送';
        } elseif ( in_array( $mode, array( 'crawler','none' ), true ) ) {
            $daily_text = '每日发现：依赖 crawler/robots/可索引性；当前无公开通用 URL 提交 API';
        } else {
            $daily_text = '每日发现：复用对应搜索体系或官方发现机制';
        }
        echo '<div class="aikey-engine-submit-daily"><strong>每日策略：</strong>' . esc_html( $daily_text ) . '</div>';
        if ( $links ) {
            echo '<div class="aikey-engine-submit-links">';
            $seen = array();
            foreach ( $links as $link ) {
                $url = esc_url( (string) ( $link['url'] ?? '' ) );
                if ( '' === $url || isset( $seen[ $url ] ) ) { continue; }
                $seen[ $url ] = true;
                $class = ! empty( $link['primary'] ) ? 'button button-secondary' : 'button';
                echo '<a class="' . esc_attr( $class ) . '" target="_blank" rel="noopener noreferrer" href="' . esc_url( $url ) . '">' . esc_html( (string) ( $link['label'] ?? '打开官方页面' ) ) . '</a>';
            }
            echo '</div>';
        }
        echo '</section>';
    }

    public function verification(): void {
        $profiles = Site_Verification::profiles();
        $this->page_title( '站点验证与统计', '集中管理搜索引擎站点所有权验证与可选百度统计。Meta 验证与访问统计彼此独立，均无需修改主题文件。' );
        $this->render_notice();
        ?>
        <div class="aikey-panel aikey-warning-panel">
            <h2>使用说明</h2>
            <p><strong>推荐直接粘贴完整 Meta 代码。</strong>插件只提取 <code>name</code> 与 <code>content</code>，不会执行 JavaScript、PHP 或任意 HTML。主题升级、更换主题不会导致验证代码丢失。</p>
            <p><strong>百度统计代码不要粘贴到 Meta 验证框。</strong>请使用本页面下方独立的“百度统计（可选）”模块；统计模块只保存 Site ID，不执行任意脚本。</p>
            <p>验证成功后建议保留代码，因为部分搜索引擎会定期重新检查网站所有权。按钮优先直达各平台的站点管理/验证页面；部分平台的唯一 Meta 验证码只有登录并选择具体站点后才会生成，无法使用固定公共 URL 直接定位到某个站点的验证码。</p>
        </div>
        <form method="post">
            <input type="hidden" name="aikey_action" value="save_verifications">
            <?php wp_nonce_field( 'aikey_save_verifications' ); ?>
            <?php foreach ( array( '中国大陆', '国际 / 跨境' ) as $region ) : ?>
                <div class="aikey-panel">
                    <h2><?php echo esc_html( $region . '搜索引擎验证' ); ?></h2>
                    <div class="aikey-verification-grid">
                    <?php foreach ( $profiles as $id => $profile ) : if ( $region !== ( $profile['region'] ?? '' ) ) { continue; } ?>
                        <div class="aikey-verification-card">
                            <div class="aikey-verification-card__head">
                                <strong><?php echo esc_html( (string) $profile['label'] ); ?></strong>
                                <a class="button button-small" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( (string) $profile['url'] ); ?>"><?php echo esc_html( (string) ( $profile['url_label'] ?? '打开站点验证页' ) ); ?></a>
                            </div>
                            <label for="verification_<?php echo esc_attr( $id ); ?>">验证码或完整 Meta 代码</label>
                            <textarea class="large-text code" rows="3" id="verification_<?php echo esc_attr( $id ); ?>" name="verification_<?php echo esc_attr( $id ); ?>" placeholder="<?php echo esc_attr( '<meta name=&quot;' . (string) $profile['default_name'] . '&quot; content=&quot;验证码&quot; />' ); ?>"><?php echo esc_textarea( Site_Verification::input_value( $id ) ); ?></textarea>
                            <p class="description">默认 Meta 名称：<code><?php echo esc_html( (string) $profile['default_name'] ); ?></code>。只粘贴验证码也可以。</p>
                            <?php if ( ! empty( $profile['note'] ) ) : ?><p class="description"><?php echo esc_html( (string) $profile['note'] ); ?></p><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="aikey-panel">
                <h2>其他搜索引擎 / 站长平台验证</h2>
                <p class="description">如果某个平台不在上面的列表中，把它给出的完整 Meta 验证代码粘贴到这里。支持最多20条，每条必须包含 <code>name</code> 和 <code>content</code>。</p>
                <textarea class="large-text code" rows="7" name="verification_custom" placeholder="<meta name=&quot;other-site-verification&quot; content=&quot;xxxx&quot; />"><?php echo esc_textarea( Site_Verification::custom_input_value() ); ?></textarea>
            </div>
            <p><button class="button button-primary button-large">保存站点验证代码</button> <a class="button button-large" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( home_url( '/' ) ); ?>">打开网站首页</a></p>
        </form>
        <div class="aikey-panel">
            <h2>公开首页验证代码检测</h2>
            <p>插件会从服务器读取公开首页 HTML，并逐条核对已经保存的 Meta <code>name</code> 和 <code>content</code>。如果这里检测不到，搜索引擎通常也无法完成验证。</p>
            <form method="post">
                <input type="hidden" name="aikey_action" value="check_verifications">
                <?php wp_nonce_field( 'aikey_check_verifications' ); ?>
                <button class="button button-secondary button-large">检测公开首页验证代码</button>
            </form>
            <p class="description">如果刚保存代码但检测仍失败，请先清理 WordPress 缓存、1Panel/Nginx 页面缓存及 CDN 缓存，然后再次检测。</p>
        </div>

        <?php
        $settings = Settings::all();
        $baidu_analytics_id = Baidu_Analytics_Module::site_id();
        $baidu_analytics_enabled = Settings::module_enabled( 'baidu_analytics' );
        $baidu_analytics_check_url = wp_nonce_url(
            add_query_arg( 'action', 'aikey_baidu_analytics_check', admin_url( 'admin-post.php' ) ),
            'aikey_baidu_analytics_check'
        );
        ?>
        <details class="aikey-panel aikey-collapsible">
            <summary><span>百度统计（可选）</span></summary>
            <div class="aikey-collapsible-content">
                <p class="description">百度统计属于第三方访问分析服务，与百度站点验证、百度普通收录 API 不是同一功能。启用后插件只按固定模板在前台 <code>&lt;head&gt;</code> 输出百度官方异步分析代码，不开放任意 JavaScript 注入。</p>
                <form method="post">
                    <input type="hidden" name="aikey_action" value="save_baidu_analytics">
                    <?php wp_nonce_field( 'aikey_save_baidu_analytics' ); ?>
                    <label><input type="checkbox" name="baidu_analytics_enabled" value="1" <?php checked( $baidu_analytics_enabled ); ?>> 启用百度统计</label>
                    <div class="aikey-field">
                        <label><strong>百度统计 Site ID</strong></label>
                        <textarea class="large-text code" rows="3" name="baidu_analytics_site_id" maxlength="4096" spellcheck="false" placeholder="例如：22544d0560d1be01dd8bf0d0e1ed83d75"><?php echo esc_textarea( $baidu_analytics_id ); ?></textarea>
                        <p class="description">推荐只填写百度统计代码中 <code>hm.js?</code> 后面的 Site ID；首次配置也可以直接粘贴完整官方统计代码，插件保存时只提取 ID，不保存脚本内容。</p>
                    </div>
                    <label><input type="checkbox" name="baidu_analytics_exclude_admins" value="1" <?php checked( ! empty( $settings['baidu_analytics']['exclude_admins'] ) ); ?>> 不统计已登录管理员访问（推荐）</label>
                    <p class="description">启用后浏览器会向 <code>hm.baidu.com</code> 发起第三方统计请求。请根据网站所在地、业务场景和隐私政策处理 Cookie/隐私告知与同意要求。</p>
                    <p><button class="button button-primary">保存百度统计设置</button> <a class="button button-secondary" href="<?php echo esc_url( $baidu_analytics_check_url ); ?>">检测公开首页安装状态</a> <a class="button" target="_blank" rel="noopener noreferrer" href="https://tongji.baidu.com/">打开百度统计</a></p>
                    <p class="description">启用前建议先检测。如果首页已经由主题、代码片段或其他插件安装百度统计，请只保留一套，避免重复统计。</p>
                </form>
            </div>
        </details>
        <?php
    }

    public function engines(): void {
        $s = Settings::all();
        $enabled = isset( $s['china_engines']['enabled'] ) && is_array( $s['china_engines']['enabled'] ) ? $s['china_engines']['enabled'] : Engine_Registry::default_enabled();
        $policies = isset( $s['china_engines']['crawler_policies'] ) && is_array( $s['china_engines']['crawler_policies'] ) ? $s['china_engines']['crawler_policies'] : Engine_Registry::default_crawler_policies();
        $bot_policies = isset( $s['china_engines']['bot_policies'] ) && is_array( $s['china_engines']['bot_policies'] ) ? $s['china_engines']['bot_policies'] : array();

        $this->page_title( '全球搜索 / AI 引擎中心', '统一管理搜索与 AI crawler 抓取策略。站点验证、Sitemap、提交和监测继续沿用已有独立入口。' );
        $this->render_notice();

        // Daily operational views stay at the top. They are read-only and outside the
        // low-frequency settings form, so a health check can never accidentally save
        // crawler policies or engine switches.

        $crawler_totals = Crawler_Monitor_Module::totals();
        ?>
        <div class="aikey-panel aikey-crawler-monitor-primary">
            <div class="aikey-panel-head">
                <div>
                    <h2>搜索 / AI crawler 抓取监测</h2>
                    <p class="description">仅按可识别 User-Agent 聚合命中次数和页面路径，不保存 IP。User-Agent 可以被伪造，因此这是运行观察数据，不是官方认证日志；没有稳定专用 UA 的平台不会为了显示数据而虚构归因。</p>
                </div>
                <span class="aikey-chip">日常监测</span>
            </div>
            <table class="widefat striped">
                <thead><tr><th>引擎 / crawler</th><th>类型</th><th>累计抓取</th><th>最近抓取</th></tr></thead>
                <tbody>
                <?php if ( ! $crawler_totals ) : ?>
                    <tr><td colspan="4">暂未检测到已知 crawler。</td></tr>
                <?php else : foreach ( $crawler_totals as $row ) : ?>
                    <?php $is_bytespider = '头条/抖音搜索生态' === (string) $row->source; ?>
                    <tr>
                        <td><strong><?php echo esc_html( $is_bytespider ? '字节 Bytespider（共享）' : (string) $row->source ); ?></strong><?php if ( $is_bytespider ) : ?><p class="description">可属于字节系网页发现/抓取活动，不能按 UA 单独归因到豆包。</p><?php endif; ?></td>
                        <td><?php echo esc_html( $is_bytespider ? '字节生态共享' : ( 'search' === $row->crawler_type ? '搜索引擎' : ( 'ai' === $row->crawler_type ? 'AI crawler' : '其他' ) ) ); ?></td>
                        <td><?php echo esc_html( (string) $row->hits ); ?></td>
                        <td><?php echo esc_html( (string) $row->last_seen ); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="aikey-panel aikey-warning-panel">
            <h2>覆盖原则</h2>
            <p>有公开且可验证的 crawler User-Agent / 提交协议时，插件才提供对应 robots 或提交控制；没有公开专用 crawler 的平台仍纳入 GEO 审计、实体一致性、来源识别和内容可引用性优化，但不会伪造 <code>DeepSeekBot</code> 一类未经确认的规则。</p>
        </div>

        <h2 class="aikey-section-title">低频配置（默认收起）</h2>
        <p class="description aikey-section-description">这些设置通常只在首次配置或策略调整时使用。robots.txt 文件本身统一在“Sitemap / robots”页面维护；这里保存“阻止抓取”策略时，只同步 Aikeyboost标记的 crawler 管理块，不覆盖其他手写规则。</p>

        <form method="post">
            <input type="hidden" name="aikey_action" value="save_engines">
            <input type="hidden" name="global_bot_policy_present" value="1">
            <?php wp_nonce_field( 'aikey_save_engines' ); ?>
            <?php
            $cn_search = array_filter( Engine_Registry::search_engines(), static fn( array $row ): bool => 'Global' !== ( $row['region'] ?? '' ) );
            $global_search = array_filter( Engine_Registry::search_engines(), static fn( array $row ): bool => 'Global' === ( $row['region'] ?? '' ) );
            $cn_ai = array_filter( Engine_Registry::ai_engines(), static fn( array $row ): bool => 'CN' === ( $row['region'] ?? '' ) );
            $global_ai = array_filter( Engine_Registry::ai_engines(), static fn( array $row ): bool => 'Global' === ( $row['region'] ?? '' ) );

            $this->engine_group_table( '中国大陆 / 中文网页搜索重点', $cn_search, $enabled, $policies, $bot_policies );
            $this->engine_group_table( '国际搜索（中国企业出海/跨境可选）', $global_search, $enabled, $policies, $bot_policies );
            $this->engine_group_table( '中国大陆主流 AI 搜索 / 回答引擎', $cn_ai, $enabled, $policies, $bot_policies );
            $this->engine_group_table( '国际 AI 引擎（中国企业出海/跨境可选）', $global_ai, $enabled, $policies, $bot_policies );
            $this->engine_group_table( '中国站外内容搜索生态', Engine_Registry::content_ecosystems(), $enabled, $policies, $bot_policies );
            ?>



            <details class="aikey-panel aikey-collapsible">
                <summary><span>百度地域信息（可选）</span></summary>
                <div class="aikey-collapsible-content">
                    <p class="description">适合本地服务型企业。只有真实、稳定的经营/服务地域才填写；不要为了排名伪造地区。</p>
                    <?php $this->setting_input( 'baidu_location_province', '省份', $s['china_engines']['baidu_location_province'] ?? '', '按百度协议填写简称，例如：安徽。省份与城市需同时填写。' ); ?>
                    <?php $this->setting_input( 'baidu_location_city', '城市', $s['china_engines']['baidu_location_city'] ?? '', '例如：合肥。' ); ?>
                    <?php $this->setting_input( 'baidu_location_coord', '坐标（可选）', $s['china_engines']['baidu_location_coord'] ?? '', '百度 bd09ll 坐标，格式为经度,纬度；没有准确坐标请留空。' ); ?>
                    <label><input type="checkbox" name="baidu_applicable_device" value="1" <?php checked( ! empty( $s['china_engines']['baidu_applicable_device'] ) ); ?>> 响应式网站输出 <code>applicable-device=pc,mobile</code></label>
                </div>
            </details>


            <p class="aikey-engine-settings-save"><button class="button button-primary button-large">保存搜索 / AI 引擎设置</button></p>
        </form>

        </div>
        <?php
    }

    private function render_sitemap_health_panel(): void {
        $last = Sitemap_Health::last_result();
        $last_run = Sitemap_Health::last_run();
        $next = Sitemap_Health::next_run();
        $sitemap = isset( $last['sitemap'] ) && is_array( $last['sitemap'] ) ? $last['sitemap'] : array();
        $robots = isset( $last['robots'] ) && is_array( $last['robots'] ) ? $last['robots'] : array();
        $stats = Sitemap_Module::runtime_stats();
        $status_label = static function ( array $row ): string {
            $status = (string) ( $row['status'] ?? '' );
            if ( 'success' === $status ) { return '正常'; }
            if ( 'warning' === $status ) { return '需检查'; }
            if ( 'error' === $status ) { return '异常'; }
            return '尚未检查';
        };
        $physical_robots = Sitemap_Health::physical_robots_exists();
        $physical_sitemap = Sitemap_Health::physical_sitemap_exists();
        $source_chip = $physical_robots ? '动态 Sitemap + 物理 robots.txt' : '动态 Sitemap + robots 待创建';
        if ( $physical_sitemap ) { $source_chip = '物理 Sitemap 冲突'; }
        $mode = (string) ( $stats['mode'] ?? '' );
        $mode_label = '尚未生成';
        if ( 'urlset' === $mode ) { $mode_label = '小站直出 URL'; }
        elseif ( 'index' === $mode ) { $mode_label = '大型站 Sitemap Index'; }
        elseif ( 'urlset-fallback' === $mode ) { $mode_label = '安全回退 URL'; }
        $generated = ! empty( $stats['generated_at'] ) ? wp_date( 'Y-m-d H:i:s', (int) $stats['generated_at'] ) : '尚未生成';
        $cached_at = ! empty( $stats['cache_updated_at'] ) ? wp_date( 'Y-m-d H:i:s', (int) $stats['cache_updated_at'] ) : '尚未缓存';
        $conflicts = Conflict_Detector::sitemap_summary();
        $native_state = Sitemap_Module::core_sitemap_is_disabled() ? '已停用（由 Aikeyboost管理）' : '保留';
        ?>
        <div class="aikey-panel aikey-sitemap-health">
            <div class="aikey-panel-head"><div><h2>Sitemap / robots.txt 健康状态</h2><p class="description">推荐主入口为 <code>/sitemap.xml</code>：有效 URL 不超过 5000 时直接输出 URL，超过 5000 时自动使用 Sitemap Index；旧 <code>/sitemap_index.xml</code> 保持兼容。robots.txt 仍以网站根目录物理文件为最终控制源。</p></div><span class="aikey-chip"><?php echo esc_html( $source_chip ); ?></span></div>
            <div class="aikey-grid aikey-grid-4">
                <div><strong>主 Sitemap</strong><p><?php echo esc_html( $status_label( $sitemap ) ); ?></p><span class="description"><?php echo esc_html( (string) ( $sitemap['message'] ?? '等待首次检查。' ) ); ?></span></div>
                <div><strong>robots.txt</strong><p><?php echo esc_html( $status_label( $robots ) ); ?></p><span class="description"><?php echo esc_html( (string) ( $robots['message'] ?? '等待首次检查。' ) ); ?></span></div>
                <div><strong>上次检查</strong><p><?php echo esc_html( $last_run ?: '尚未运行' ); ?></p><span class="description">WP-Cron 为访问触发，不承诺精确到分钟。</span></div>
                <div><strong>下次计划</strong><p><?php echo esc_html( $next ? wp_date( 'Y-m-d H:i', $next ) : '未计划' ); ?></p><span class="description">Sitemap 模块开启且站点允许索引时每天检查。</span></div>
            </div>
            <div class="aikey-grid aikey-grid-4" style="margin-top:12px">
                <div><strong>有效 URL 总数</strong><p><?php echo esc_html( isset( $stats['total'] ) ? (string) (int) $stats['total'] : '—' ); ?></p><span class="description"><?php echo esc_html( $mode_label ); ?></span></div>
                <div><strong>Page / Post</strong><p><?php echo esc_html( isset( $stats['page'] ) ? (int) $stats['page'] . ' / ' . (int) ( $stats['post'] ?? 0 ) : '—' ); ?></p><span class="description">首页使用静态 Page 时计入 Page。</span></div>
                <div><strong>CPT / Taxonomy</strong><p><?php echo esc_html( isset( $stats['cpt'] ) ? (int) $stats['cpt'] . ' / ' . (int) ( $stats['taxonomy'] ?? 0 ) : '—' ); ?></p><span class="description">仅统计已启用且最终进入 Sitemap 的 URL。</span></div>
                <div><strong>最后生成 / 缓存</strong><p><?php echo esc_html( $generated ); ?></p><span class="description"><?php echo esc_html( Sitemap_Module::main_cache_ready() ? '缓存：' . $cached_at : '等待下次访问生成/缓存' ); ?></span></div>
            </div>
            <div style="margin-top:12px"><strong>WordPress 原生 Sitemap：</strong><?php echo esc_html( $native_state ); ?><?php if ( '' !== $conflicts ) : ?> <span class="description">｜检测到 Sitemap/SEO 插件：<?php echo esc_html( $conflicts ); ?>；未强制接管时 Aikeyboost不会静默重复输出。</span><?php endif; ?></div>
            <div class="aikey-sitemap-actions"><form method="post"><?php wp_nonce_field( 'aikey_check_sitemap_health' ); ?><input type="hidden" name="aikey_action" value="check_sitemap_health"><button class="button button-primary">立即检测 Sitemap</button></form><a class="button" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( Sitemap_Module::main_url() ); ?>">打开主 Sitemap</a><a class="button" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>">打开 robots.txt</a></div>
        </div>
        <?php
    }

    private function engine_group_table( string $title, array $platforms, array $enabled, array $policies, array $bot_policies = array() ): void {
        echo '<details class="aikey-panel aikey-collapsible aikey-engine-group">';
        echo '<summary><span>' . esc_html( $title ) . '</span><small>' . esc_html( (string) count( $platforms ) ) . ' 个平台</small></summary>';
        echo '<div class="aikey-collapsible-content">';
        echo '<table class="widefat striped aikey-engine-table"><thead><tr><th style="width:70px">启用</th><th>引擎</th><th>crawler / robots token</th><th style="width:170px">robots 策略</th><th>当前覆盖</th></tr></thead><tbody>';
        foreach ( $platforms as $id => $platform ) {
            $bots = ! empty( $platform['bots'] ) ? implode( ', ', (array) $platform['bots'] ) : '暂无公开专用 UA';
            echo '<tr><td><input type="checkbox" name="engine_enabled_' . esc_attr( $id ) . '" value="1" ' . checked( ! empty( $enabled[ $id ] ), true, false ) . '></td><td><strong>' . esc_html( (string) $platform['label'] ) . '</strong>';
            if ( ! empty( $platform['note'] ) ) { echo '<p class="description">' . esc_html( (string) $platform['note'] ) . '</p>'; }
            echo '</td><td><code>' . esc_html( $bots ) . '</code></td><td>';
            if ( ! empty( $platform['bots'] ) ) {
                $current = (string) ( $policies[ $id ] ?? 'allow' );
                echo '<select name="engine_policy_' . esc_attr( $id ) . '"><option value="allow" ' . selected( $current, 'allow', false ) . '>允许（不新增阻止）</option><option value="block" ' . selected( $current, 'block', false ) . '>阻止抓取</option><option value="unchanged" ' . selected( $current, 'unchanged', false ) . '>不处理</option></select>';
                $individual = Global_Crawler_Policy::for_platform( (string) $id );
                if ( $individual ) {
                    echo '<details style="margin-top:8px"><summary>按用途分别设置（可选）</summary>';
                    foreach ( $individual as $token => $description ) {
                        $choice = Global_Crawler_Policy::choice( $bot_policies, (string) $id, (string) $token );
                        $field = 'global_bot_policy[' . esc_attr( $id ) . '][' . esc_attr( $token ) . ']';
                        echo '<label style="display:block;margin-top:8px"><code>' . esc_html( (string) $token ) . '</code> — ' . esc_html( (string) $description['description'] ) . '<br>';
                        echo '<select name="' . esc_attr( $field ) . '"><option value="inherit" ' . selected( $choice, 'inherit', false ) . '>继承上方策略（默认）</option><option value="allow" ' . selected( $choice, 'allow', false ) . '>不由本插件新增阻止</option><option value="block" ' . selected( $choice, 'block', false ) . '>阻止该 token</option></select></label>';
                    }
                    echo '<p class="description">搜索、用户请求与训练可分别控制。只更新本插件管理的 robots 规则，不能覆盖手写规则、服务器或 CDN 策略。</p></details>';
                }
            } else { echo '—'; }
            echo '</td><td>' . esc_html( (string) ( $platform['coverage'] ?? '' ) ) . '</td></tr>';
        }
        echo '</tbody></table></div></details>';
    }

    public function referrals(): void {
        $this->page_title( '搜索 / AI 来源监测', '基于 HTTP Referer 聚合识别百度、搜狗、360、神马、必应及主流 AI/内容平台可见引荐访问；不使用 Cookie、不记录 IP。部分 App/AI 平台不会传递 Referer，因此这里只代表可识别来源。' );
        echo '<div class="aikey-grid">'; foreach ( array_slice( Referral_Module::totals(), 0, 6 ) as $row ) { $this->card( $row->source, (string) $row->hits, '累计可识别访问', 'ok' ); } echo '</div>';
        echo '<div class="aikey-panel"><table class="widefat striped"><thead><tr><th>来源</th><th>页面路径</th><th>次数</th><th>首次</th><th>最近</th></tr></thead><tbody>'; foreach ( Referral_Module::rows() as $row ) { echo '<tr><td>' . esc_html( $row->source ) . '</td><td><code>' . esc_html( $row->path ) . '</code></td><td>' . esc_html( (string) $row->hits ) . '</td><td>' . esc_html( (string) $row->first_seen ) . '</td><td>' . esc_html( (string) $row->last_seen ) . '</td></tr>'; } echo '</tbody></table></div></div>';
    }


    public function sitemap(): void {
        $s = Settings::all();
        $this->page_title( 'Sitemap / robots.txt', 'Sitemap 生成、健康检测、收录范围与物理 robots.txt 统一在这里管理；其他页面不再提供第二套 Sitemap 配置入口。' );
        $this->render_notice();
        $this->render_sitemap_health_panel();
        $this->render_sitemap_settings_panel( $s );
        $this->render_robots_manager();
        echo '</div>';
    }

    private function render_sitemap_settings_panel( array $s ): void {
        $physical_sitemap = Sitemap_Health::physical_sitemap_exists();
        ?>
        <div class="aikey-panel">
            <div class="aikey-panel-head"><div><h2>Sitemap 生成规则</h2><p class="description">推荐主入口固定为 <code>/sitemap.xml</code>。有效 URL ≤5000 时直接输出完整 <code>urlset</code>；超过 5000 时自动使用 Sitemap Index。这里只有一套 Sitemap 配置。</p></div></div>
            <?php if ( $physical_sitemap ) : ?><div class="notice notice-error inline"><p><strong>检测到物理 Sitemap 冲突文件：</strong>网站根目录存在 sitemap.xml 或 sitemap_index.xml，可能覆盖插件动态路由，请在服务器文件管理中移除或改名该物理 XML 文件。</p></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="aikey_action" value="save_sitemap_settings">
                <?php wp_nonce_field( 'aikey_save_sitemap_settings' ); ?>
                <?php $this->setting_input( 'sitemap_max_urls','大型站点每个子 Sitemap 最大 URL',$s['sitemap']['max_urls'] ?? 5000,'100–5000，建议保持 5000；不改变小站 ≤5000 直接输出单一 urlset 的规则。' ); ?>
                <label><input type="checkbox" name="sitemap_disable_core" value="1" <?php checked( ! empty( $s['sitemap']['disable_core_sitemap'] ) ); ?>> 停用 WordPress 原生 <code>/wp-sitemap.xml</code>（推荐：由 Aikeyboost <code>/sitemap.xml</code> 作为唯一主 Sitemap）</label>
                <div class="aikey-field"><label><strong>进入 Sitemap 的内容类型</strong></label>
                    <p class="description">Page、Post 与具有真实前台路由的公开 CPT 默认开启；attachment 与后台数据型内容自动排除。</p>
                    <?php $saved_post_types = isset( $s['sitemap']['post_types'] ) && is_array( $s['sitemap']['post_types'] ) ? $s['sitemap']['post_types'] : array(); foreach ( Sitemap_Content::available_post_types() as $type => $label ) : $enabled = array_key_exists( $type, $saved_post_types ) ? ! empty( $saved_post_types[ $type ] ) : true; ?>
                        <label style="display:inline-block;margin:4px 18px 4px 0"><input type="checkbox" name="sitemap_post_types[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( $enabled ); ?>> <?php echo esc_html( $label . ' (' . $type . ')' ); ?></label>
                    <?php endforeach; ?>
                </div>
                <div class="aikey-field"><label><strong>进入 Sitemap 的分类体系</strong></label>
                    <p class="description">category 默认开启；post_tag 与第三方 Taxonomy 默认关闭，避免企业站薄内容/重复归档无脑进入 Sitemap。</p>
                    <?php $saved_taxonomies = isset( $s['sitemap']['taxonomies'] ) && is_array( $s['sitemap']['taxonomies'] ) ? $s['sitemap']['taxonomies'] : array(); foreach ( Sitemap_Content::available_taxonomies() as $taxonomy => $label ) : $default = 'category' === $taxonomy; $enabled = array_key_exists( $taxonomy, $saved_taxonomies ) ? ! empty( $saved_taxonomies[ $taxonomy ] ) : $default; ?>
                        <label style="display:inline-block;margin:4px 18px 4px 0"><input type="checkbox" name="sitemap_taxonomies[]" value="<?php echo esc_attr( $taxonomy ); ?>" <?php checked( $enabled ); ?>> <?php echo esc_html( $label . ' (' . $taxonomy . ')' ); ?></label>
                    <?php endforeach; ?>
                </div>
                <div class="aikey-field"><label><strong>Sitemap 排除规则（可选）</strong></label>
                    <p class="description">页面/文章 ID 用逗号或换行分隔；URL 每行一个且必须属于当前站点。首页仍按真实 index/canonical 状态处理。</p>
                    <label>排除 ID</label><textarea class="large-text code" rows="3" name="sitemap_exclude_post_ids"><?php echo esc_textarea( implode( "\n", array_map( 'absint', (array) ( $s['sitemap']['exclude_post_ids'] ?? array() ) ) ) ); ?></textarea>
                    <label style="display:block;margin-top:8px">排除 URL</label><textarea class="large-text code" rows="4" name="sitemap_exclude_urls"><?php echo esc_textarea( implode( "\n", array_map( 'strval', (array) ( $s['sitemap']['exclude_urls'] ?? array() ) ) ) ); ?></textarea>
                </div>
                <?php $this->setting_input( 'baidu_direct_limit','百度直连兼容 Sitemap 最大 URL',$s['sitemap']['baidu_direct_limit'] ?? 50000,'仅保留旧版 /baidu-sitemap.xml 兼容入口；新的主 Sitemap 始终是 /sitemap.xml。' ); ?>
                <p><button class="button button-primary button-large">保存 Sitemap 设置</button></p>
            </form>
        </div>
        <?php
    }

    private function render_robots_manager(): void {
        $robots_exists = Robots_File_Manager::exists();
        $robots_writable = Robots_File_Manager::writable();
        $robots_read = Robots_File_Manager::read();
        $robots_read_error = is_wp_error( $robots_read ) ? $robots_read : null;
        $robots_content = '';
        if ( ! is_wp_error( $robots_read ) ) {
            $robots_content = (string) $robots_read;
        } elseif ( 'robots_missing' === $robots_read->get_error_code() ) {
            $robots_content = Robots_File_Manager::editor_content();
        }
        $robots_can_save = $robots_writable && ( ! $robots_read_error || 'robots_missing' === $robots_read_error->get_error_code() );
        ?>
        <details class="aikey-panel aikey-collapsible">
            <summary><span>物理 robots.txt（高级，默认收起）</span></summary>
            <div class="aikey-collapsible-content">
                <div class="aikey-field"><label><strong>物理 robots.txt</strong></label><p><?php echo esc_html( $robots_exists ? '已存在' : '尚未创建' ); ?> · <?php echo esc_html( $robots_writable ? '当前可写' : '当前不可写' ); ?></p><p class="description">只管理网站公开根目录固定 <code>/robots.txt</code>，不接受自定义文件路径。</p></div>
                <?php if ( $robots_read_error && 'robots_missing' !== $robots_read_error->get_error_code() ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( $robots_read_error->get_error_message() ); ?></p></div><?php endif; ?>
                <form method="post">
                    <input type="hidden" name="aikey_action" value="save_robots_file"><?php wp_nonce_field( 'aikey_save_robots_file' ); ?>
                    <div class="aikey-field"><label><strong>robots.txt 内容</strong></label><textarea class="large-text code" rows="14" name="robots_file_content" maxlength="65536" spellcheck="false" placeholder="User-agent: *&#10;Allow: /&#10;Disallow: /wp-admin/&#10;Allow: /wp-admin/admin-ajax.php&#10;&#10;Sitemap: https://example.com/sitemap.xml"><?php echo esc_textarea( $robots_content ); ?></textarea><p class="description">最多 64 KB。保存时自动统一插件主 Sitemap 声明，并同步“中国引擎”中明确阻止的 crawler 管理块；管理块外的手写规则保持不变。</p></div>
                    <p><button class="button button-primary" <?php echo $robots_can_save ? '' : 'disabled="disabled"'; ?>><?php echo esc_html( $robots_exists ? '保存并更新物理 robots.txt' : '保存并创建物理 robots.txt' ); ?></button> <a class="button" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>">打开 robots.txt</a></p>
                </form>
            </div>
        </details>
        <?php
    }

    public function settings(): void {
        $s = Settings::all();
        $this->page_title( '基础设置', '这里只保留全站默认规则、企业实体、企业地图、llms.txt 与 AI 接口等真正的全局配置；页面级 SEO、Sitemap、提交和验证均在各自唯一入口维护。' );
        $this->render_notice();
        ?>
        <form method="post"><input type="hidden" name="aikey_action" value="save_settings"><?php wp_nonce_field( 'aikey_save_settings' ); ?>
            <div class="aikey-panel"><h2>基础与兼容</h2><label><input type="checkbox" name="force_takeover" value="1" <?php checked( ! empty( $s['force_takeover'] ) ); ?>> 即使检测到其他 SEO 插件也强制由 Aikeyboost输出前台 SEO（不推荐同时启用）</label><br><label><input type="checkbox" name="keep_data_on_uninstall" value="1" <?php checked( ! empty( $s['keep_data_on_uninstall'] ) ); ?>> 卸载时保留 Aikeyboost数据</label></div>
            <div class="aikey-panel"><h2>全站 SEO 默认规则</h2><?php $this->setting_input( 'title_template','默认标题模板',$s['seo_meta']['title_template'],'只作为页面级 SEO 标题为空时的全站 fallback。' ); $this->setting_input( 'separator','标题分隔符',$s['seo_meta']['separator'],'-' ); ?>
                <p class="description"><strong>首页和其他页面的 SEO Title / Meta Description 不在这里重复设置。</strong>请在“优化工作台”或对应 WordPress 页面编辑器维护；旧版本保存的首页全局数据仅作为兼容回退，无需重复维护。</p>
                <label><input type="checkbox" name="auto_description_fallback" value="1" <?php checked( ! empty( $s['seo_meta']['auto_description_fallback'] ) ); ?>> 页面描述为空时自动从正文生成</label><br><label><input type="checkbox" name="open_graph" value="1" <?php checked( ! empty( $s['seo_meta']['open_graph'] ) ); ?>> 输出 Open Graph / Twitter Card</label></div>
            <div class="aikey-panel">
                <h2>企业实体 / Schema</h2>
                <div class="aikey-field">
                    <label><strong>企业类型</strong></label>
                    <select class="regular-text" name="organization_type">
                        <?php foreach ( array( 'Organization'=>'Organization', 'LocalBusiness'=>'LocalBusiness', 'Corporation'=>'Corporation' ) as $key => $value ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['schema']['organization_type'], $key ); ?>><?php echo esc_html( $value ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php
                $this->setting_input( 'organization_name', '企业/组织名称', $s['schema']['organization_name'] );
                $this->setting_input( 'logo_url', 'Logo URL', $s['schema']['logo_url'] );
                $this->setting_input( 'telephone', '电话', $s['schema']['telephone'] );
                $this->setting_input( 'email', '邮箱', $s['schema']['email'] );
                $this->setting_input( 'address', '地址', $s['schema']['address'] );
                $this->setting_input( 'service_area', '主要服务区域', $s['schema']['service_area'] ?? '中国大陆' );
                $this->setting_input( 'unified_social_credit_code', '统一社会信用代码', $s['schema']['unified_social_credit_code'] ?? '' );
                $this->setting_input( 'icp_number', 'ICP备案号', $s['schema']['icp_number'] ?? '' );
                $this->setting_textarea( 'same_as', '外部实体链接（每行一个）', $s['schema']['same_as'], '仅填写可验证的企业页面。' );
                $this->setting_textarea(
                    'schema_localizations', '多语言企业资料',
                    \AIKEY\SeoGeo\SEO\Schema\Localized_Entity::format(
                        (array) ( $s['schema']['localized_organizations'] ?? array() )
                    ),
                    '一行一个语言：语言代码 | 企业名 | 地址 | 企业介绍 | 服务范围。各字段必填，保存遇到无效行会拒绝整次提交。'
                );
                ?>
            </div>
            <div class="aikey-panel" id="aikey-enterprise-map-settings"><h2>企业地图与联系表单</h2><p class="description">纯轻量模式：企业位置 + 官方地图入口 + 联系表单；无需地图 API。不同 WordPress 网站在各自后台独立设置地址。</p><p><strong>前台使用方法：</strong>打开需要展示的页面，在 WordPress 编辑器中添加“短代码”区块，粘贴 <code>[aikey_enterprise_map]</code> 后保存；也可以添加“Aikeyboost企业地图”区块。短代码会同时显示地图卡片与联系表单。</p><p class="description">默认表单地址和地图入口在这里配置；客户留言在“SEO&GEO → 表单信息”中查看。如需中文、英文、法语等独立表单，请进入 <a href="<?php echo esc_url( admin_url( 'admin.php?page=aikey-seo-geo-forms' ) ); ?>">SEO&GEO → 表单管理</a>，每个表单都有独立短代码。</p><?php $this->setting_input( 'map_address','地图展示地址（可选）',$s['map']['address'] ?? '','填写当前网站需要展示的实际地址；留空时使用上方“企业实体 / Schema”的地址。这里只修改地图和导航搜索地址，不修改 Schema 结构化数据。' ); $this->setting_input( 'map_height','默认地图卡片高度（px）',$s['map']['height'] ?? 340,'220–720；Gutenberg 区块还可以单独调整。' ); ?><label><input type="checkbox" name="map_show_address" value="1" <?php checked( ! empty( $s['map']['show_address'] ) ); ?>> 卡片下方显示企业名称与地址</label><br><label><input type="checkbox" name="map_show_navigation" value="1" <?php checked( ! empty( $s['map']['show_navigation'] ) ); ?>> 显示“打开地图”按钮</label><div class="aikey-field"><label><strong>地图入口</strong></label><select class="regular-text" name="map_navigation_provider"><option value="both" <?php selected( $s['map']['navigation_provider'] ?? 'both', 'both' ); ?>>高德 + 百度（推荐）</option><option value="amap" <?php selected( $s['map']['navigation_provider'] ?? 'both', 'amap' ); ?>>仅高德</option><option value="baidu" <?php selected( $s['map']['navigation_provider'] ?? 'both', 'baidu' ); ?>>仅百度</option></select></div></div>
            <div class="aikey-panel"><h2>AI / GEO 通用</h2><?php $this->setting_input( 'llms_max_links','llms.txt 最大页面数',$s['llms']['max_links'],'只控制 /llms.txt 的页面数量，不影响 Sitemap。' ); ?></div>
            <div class="aikey-panel"><h2>DeepSeek AI</h2><p class="description">API Key 可保存在这里；生产环境也可在 <code>wp-config.php</code> 定义 <code>AIKEY_DEEPSEEK_API_KEY</code>，常量优先。</p><?php $this->setting_input( 'ai_base_url','API Base URL',$s['ai']['base_url'] ); $this->setting_input( 'ai_model','模型',$s['ai']['model'] ); ?><div class="aikey-field"><label><strong>API Key</strong></label><input class="regular-text" type="password" name="ai_api_key" value="" placeholder="留空保持现有 Key"><p class="description">当前状态：<?php echo esc_html( '' !== Settings::api_key() ? '已配置' : '未配置' ); ?></p></div></div>
            <p><button class="button button-primary button-large">保存基础设置</button></p>
        </form></div><?php
    }

    private function page_title( string $title, string $desc ): void {
        echo '<div class="wrap aikey-wrap"><div class="aikey-page-head"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $desc ) . '</p></div>';
        Admin_Navigation::render();
    }
    private function card( string $title, string $value, string $text, string $state ): void { echo '<div class="aikey-card aikey-card-' . esc_attr( $state ) . '"><span>' . esc_html( $title ) . '</span><strong>' . esc_html( $value ) . '</strong><p>' . esc_html( $text ) . '</p></div>'; }
    private function score_badge( int $score ): string { $class = $score >= 80 ? 'good' : ( $score >= 60 ? 'mid' : 'bad' ); return '<span class="aikey-score-badge ' . esc_attr( $class ) . '">' . esc_html( (string) $score ) . '</span>'; }
    private function setting_input( string $name, string $label, mixed $value, string $help = '' ): void { echo '<div class="aikey-field"><label><strong>' . esc_html( $label ) . '</strong></label><input class="regular-text" type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">'; if ( $help ) { echo '<p class="description">' . esc_html( $help ) . '</p>'; } echo '</div>'; }
    private function setting_textarea( string $name, string $label, mixed $value, string $help = '' ): void { echo '<div class="aikey-field"><label><strong>' . esc_html( $label ) . '</strong></label><textarea class="large-text" rows="4" name="' . esc_attr( $name ) . '">' . esc_textarea( (string) $value ) . '</textarea>'; if ( $help ) { echo '<p class="description">' . esc_html( $help ) . '</p>'; } echo '</div>'; }
    private function raw( string $name ): string { return isset( $_POST[ $name ] ) ? (string) wp_unslash( $_POST[ $name ] ) : ''; }
    private function text( string $name, string $default = '', int $max = 500 ): string { $value = sanitize_text_field( $this->raw( $name ) ); return function_exists( 'mb_substr' ) ? mb_substr( $value ?: $default, 0, $max, 'UTF-8' ) : substr( $value ?: $default, 0, $max ); }
    private function textarea( string $name, string $default = '', int $max = 5000 ): string { $value = sanitize_textarea_field( $this->raw( $name ) ); $value = '' !== $value ? $value : $default; return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max, 'UTF-8' ) : substr( $value, 0, $max ); }
    private function url( string $name ): string { return esc_url_raw( trim( $this->raw( $name ) ), array( 'http','https' ) ); }
    private function notice( string $type, string $message ): void { set_transient( 'aikey_notice_' . get_current_user_id(), array( 'type'=>$type,'message'=>$message ), 60 ); }
    private function render_notice(): void { $key = 'aikey_notice_' . get_current_user_id(); $notice = get_transient( $key ); if ( is_array( $notice ) ) { delete_transient( $key ); $type = in_array( (string) ( $notice['type'] ?? '' ), array( 'error','warning','success','info' ), true ) ? (string) $notice['type'] : 'success'; echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>'; } }
    private function go( string $page ): void { wp_safe_redirect( add_query_arg( 'page', $page, admin_url( 'admin.php' ) ) ); exit; }
}
