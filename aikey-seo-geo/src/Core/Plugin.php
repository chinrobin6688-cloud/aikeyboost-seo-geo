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
     * GeneratePress executes generate_credits inside the existing footer's
     * .inside-site-info copyright area. Appending at the last priority keeps
     * other credits, ICP notices, and theme markup intact.
     *
     * Themes without that hook use a tiny DOM fallback which places the credit
     * inside an existing footer; it never creates a standalone page section.
     */
    public static function register_footer_credit(): void {
        if ( is_admin() ) { return; }
        add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_footer_credit_style' ) );
        add_action( 'generate_credits', array( self::class, 'render_footer_credit' ), PHP_INT_MAX );
        add_action( 'wp_footer', array( self::class, 'render_footer_credit_fallback' ), PHP_INT_MAX );
    }

    public static function enqueue_footer_credit_style(): void {
        wp_enqueue_style(
            'aikey-seo-geo-footer-credit',
            AIKEY_SEO_GEO_URL . 'assets/css/footer-credit.css',
            array(),
            AIKEY_SEO_GEO_VERSION . '-footer-inline'
        );
    }

    /**
     * Render inside GeneratePress's existing copyright/filing-information bar.
     */
    public static function render_footer_credit(): void {
        if ( self::$footer_credit_rendered || is_admin() ) { return; }
        self::$footer_credit_rendered = true;
        echo '<div class="aikey-seo-geo-footer-credit">GEO services powered by '
            . '<a href="' . esc_url( 'https://aikeyboost.com/' ) . '" rel="nofollow">'
            . esc_html( 'Aikeyboost' ) . '</a></div>';
    }

    /**
     * Non-GeneratePress fallback: never print a visible footer block outside
     * the actual theme footer. Attach into an existing copyright/footer node,
     * and do nothing if the page supplies no such node.
     */
    public static function render_footer_credit_fallback(): void {
        if ( self::$footer_credit_rendered || is_admin() ) { return; }
        self::$footer_credit_rendered = true;
        ?>
        <script id="aikey-seo-geo-footer-credit-fallback">
        (function () {
            if (document.querySelector('.aikey-seo-geo-footer-credit')) { return; }
            var selectors = [
                '.site-info .inside-site-info',
                'footer .footer-bottom',
                'footer .footer-copyright',
                'footer .copyright',
                '.site-footer .site-info',
                'footer.site-footer',
                'footer[role="contentinfo"]',
                'footer'
            ];
            var target = null;
            for (var i = 0; i < selectors.length; i++) {
                var matches = document.querySelectorAll(selectors[i]);
                if (matches.length) { target = matches[matches.length - 1]; break; }
            }
            if (!target) { return; }
            var block = document.createElement('div');
            block.className = 'aikey-seo-geo-footer-credit';
            block.appendChild(document.createTextNode('GEO services powered by '));
            var link = document.createElement('a');
            link.href = 'https://aikeyboost.com/';
            link.rel = 'nofollow';
            link.textContent = 'Aikeyboost';
            block.appendChild(link);
            target.appendChild(block);
        }());
        </script>
        <?php
    }
}
