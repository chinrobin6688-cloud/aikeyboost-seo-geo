<?php
namespace AIKEY\SeoGeo\Core;

use AIKEY\SeoGeo\Admin\Admin;
use AIKEY\SeoGeo\Admin\Article_Manager;
use AIKEY\SeoGeo\REST\Status_Controller;
use AIKEY\SeoGeo\Optimization\Optimization_Workbench;
use AIKEY\SeoGeo\Integrations\Daily_Submission;
use AIKEY\SeoGeo\Integrations\Site_Verification;
use AIKEY\SeoGeo\Integrations\Global_Diagnostics;
use AIKEY\SeoGeo\Integrations\Webmaster_Data;
use AIKEY\SeoGeo\Integrations\Google_Search_Console;
use AIKEY\SeoGeo\SEO\Sitemap\Sitemap_Health;
use AIKEY\SeoGeo\SEO\International\International_Module;
use AIKEY\SeoGeo\SEO\International\Translation_Admin;
use AIKEY\SeoGeo\SEO\International\Translation_Groups;
use AIKEY\SeoGeo\SEO\International\Language_Switcher;

defined( 'ABSPATH' ) || exit;

final class Plugin {
    private static ?self $instance = null;
    private bool $booted = false;
    private static bool $footer_credit_rendered = false;

    public static function instance(): self {
        if ( null === self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }
    private function __construct() {}

    public function boot(): void {
        if ( $this->booted ) { return; }
        $this->booted = true;
        ( new Upgrade_Manager() )->maybe_upgrade();
        ( new Module_Manager() )->register();
        Optimization_Workbench::register_hooks();
        Daily_Submission::register_hooks();
        Site_Verification::register_hooks();
        Sitemap_Health::register_hooks();
        ( new International_Module() )->register();
        Translation_Admin::register_hooks();
        Language_Switcher::register_hooks();
        add_action( 'before_delete_post', array( Translation_Groups::class, 'on_delete' ), 10, 1 );
        Global_Diagnostics::register_hooks();
        Webmaster_Data::register_hooks();
        Google_Search_Console::register_hooks();
        self::register_footer_credit();
        if ( is_admin() ) {
            ( new Admin() )->register();
            ( new Article_Manager() )->register();
        }
        ( new Status_Controller() )->register();
    }

    /**
     * Add an independent, optional footer attribution without touching the
     * theme's existing copyright, widgets or footer markup.
     *
     * GeneratePress exposes a dedicated hook inside its footer. Other themes,
     * including block themes, use the standard wp_footer fallback. The shared
     * guard prevents duplicate output when a theme fires both hooks.
     */
    public static function register_footer_credit(): void {
        if ( is_admin() || ! (bool) Settings::get( 'show_footer_credit', false ) ) { return; }
        add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_footer_credit_style' ) );
        add_action( 'generate_after_footer', array( self::class, 'render_footer_credit' ), 20 );
        add_action( 'wp_footer', array( self::class, 'render_footer_credit' ), 20 );
    }

    public static function enqueue_footer_credit_style(): void {
        if ( ! (bool) Settings::get( 'show_footer_credit', false ) ) { return; }
        wp_enqueue_style(
            'aikey-seo-geo-footer-credit',
            AIKEY_SEO_GEO_URL . 'assets/css/footer-credit.css',
            array(),
            AIKEY_SEO_GEO_VERSION
        );
    }

    public static function render_footer_credit(): void {
        if ( self::$footer_credit_rendered || is_admin()
            || ! (bool) Settings::get( 'show_footer_credit', false ) ) {
            return;
        }
        self::$footer_credit_rendered = true;
        echo '<div class="aikey-seo-geo-footer-credit">GEO services powered by '
            . '<a href="' . esc_url( 'https://aikeyboost.com/' ) . '" rel="nofollow">'
            . esc_html( 'Aikeyboost' ) . '</a></div>';
    }
}
