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
     * Private distribution: frontend branding follows the stored site setting.
     * New sites default on; any saved opt-out takes precedence.
     */
    public static function register_footer_credit(): void {
        if ( ! (bool) Settings::get( 'show_footer_credit', false ) ) { return; }
        add_filter( 'generate_copyright', array( self::class, 'footer_credit' ), 99 );
        add_filter( 'widget_block_content', array( self::class, 'footer_credit' ), 20 );
    }

    /** Replace matching existing attribution only while the site setting is enabled. */
    public static function footer_credit( string $copyright ): string {
        if ( ! (bool) Settings::get( 'show_footer_credit', false )
            || false === stripos( $copyright, 'Built with' ) ) {
            return $copyright;
        }
        $credit = 'GEO services powered by <a href="https://aikeyboost.com/" rel="nofollow">Aikeyboost</a>';
        $copyright = (string) preg_replace(
            '~Built\s+with\s+(?:<a\b[^>]*>\s*)?GeneratePress(?:\s*</a>)?~i',
            $credit, $copyright, 1
        );
        // Actual Footer Bar block: "Built with&nbsp;<a href=...>AI企帮</a>".
        return (string) preg_replace(
            '~Built\s+with(?:\s|&nbsp;)+<a href="https://aikeyboost\.com/">AI企帮</a>~u',
            $credit, $copyright, 1
        );
    }
}
