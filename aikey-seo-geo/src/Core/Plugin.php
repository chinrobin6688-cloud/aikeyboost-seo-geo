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
     * GeneratePress fires generate_credits inside the existing copyright bar.
     * Append inline text to inherit the theme's font, color, and line height.
     */
    public static function register_footer_credit(): void {
        if ( is_admin() ) { return; }

        add_action( 'generate_credits', array( self::class, 'render_footer_credit' ), PHP_INT_MAX );

        // Non-GeneratePress themes: append only to a recognized copyright line.
        add_action( 'wp_footer', array( self::class, 'render_footer_credit_fallback' ), PHP_INT_MAX );
    }

    public static function render_footer_credit(): void {
        if ( self::$footer_credit_rendered || is_admin() ) { return; }

        self::$footer_credit_rendered = true;
        echo ' <span class="aikey-seo-geo-inline-credit">&middot; GEO services powered by '
            . '<a href="' . esc_url( 'https://aikeyboost.com/' ) . '" rel="nofollow">'
            . esc_html( 'Aikeyboost' ) . '</a></span>';
    }

    /**
     * No separate block and no style overrides. On other themes, inject the
     * same inline span only when an existing copyright line can be identified.
     */
    public static function render_footer_credit_fallback(): void {
        if ( self::$footer_credit_rendered || is_admin() ) { return; }

        self::$footer_credit_rendered = true;
        ?>
        <script id="aikey-seo-geo-credit-fallback">
        (function () {
            if (document.querySelector('.aikey-seo-geo-inline-credit')) { return; }
            var target = document.querySelector(
                '.site-info .copyright-bar, .site-info .copyright, ' +
                '.footer-copyright .copyright, footer .copyright-bar, ' +
                'footer .footer-copyright, footer .copyright'
            );
            if (!target) { return; }
            var span = document.createElement('span');
            span.className = 'aikey-seo-geo-inline-credit';
            span.appendChild(document.createTextNode(' \u00b7 GEO services powered by '));
            var link = document.createElement('a');
            link.href = 'https://aikeyboost.com/';
            link.rel = 'nofollow';
            link.textContent = 'Aikeyboost';
            span.appendChild(link);
            target.appendChild(span);
        }());
        </script>
        <?php
    }
}
