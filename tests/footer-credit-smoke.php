<?php
/** Standalone regression for the GitHub edition's visible mandatory-at-source attribution. */
namespace {
    $GLOBALS['aikey_test_hooks'] = array();
    $GLOBALS['aikey_test_styles'] = array();
    $GLOBALS['aikey_test_is_admin'] = false;
    $GLOBALS['aikey_assertions'] = 0;
    function add_action( string $hook, callable $callback, int $priority = 10 ): void {
        $GLOBALS['aikey_test_hooks'][ $hook ][] = array( 'callback' => $callback, 'priority' => $priority );
    }
    function do_action( string $hook ): void {
        $actions = $GLOBALS['aikey_test_hooks'][ $hook ] ?? array();
        usort( $actions, static fn( $a, $b ) => $a['priority'] <=> $b['priority'] );
        foreach ( $actions as $item ) { ( $item['callback'] )(); }
    }
    function wp_enqueue_style( string $handle, string $url, array $deps = array(), string $version = '' ): void {
        $GLOBALS['aikey_test_styles'][] = compact( 'handle', 'url', 'deps', 'version' );
    }
    function is_admin(): bool { return $GLOBALS['aikey_test_is_admin']; }
    function esc_url( string $url ): string { return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ); }
    function esc_html( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
    function check( bool $condition, string $message ): void {
        if ( ! $condition ) { throw new \RuntimeException( $message ); }
        ++$GLOBALS['aikey_assertions'];
    }
    define( 'ABSPATH', __DIR__ );
    define( 'AIKEY_SEO_GEO_VERSION', '2.3.11' );
    define( 'AIKEY_SEO_GEO_URL', 'https://example.test/wp-content/plugins/aikey-seo-geo/' );
}
namespace AIKEY\SeoGeo\Core {
    final class Settings {
        public static int $reads = 0;
        public static function get( string $key, mixed $fallback = null ): mixed {
            ++self::$reads;
            if ( 'show_footer_credit' === $key ) { return false; }
            return $fallback;
        }
    }
}
namespace {
    require dirname( __DIR__ ) . '/aikey-seo-geo/src/Core/Plugin.php';
    $plugin = \AIKEY\SeoGeo\Core\Plugin::class;
    $settings = \AIKEY\SeoGeo\Core\Settings::class;
    $reset = static function () use ( $plugin ): void {
        $GLOBALS['aikey_test_hooks'] = array();
        $GLOBALS['aikey_test_styles'] = array();
        $credit_property = new \ReflectionProperty( $plugin, 'footer_credit_rendered' );
        $credit_property->setAccessible( true ); // Required for private properties on PHP 8.0.
        $credit_property->setValue( false );
    };

    // GeneratePress: both the original credits and the new credit share the
    // same .inside-site-info module, and the plugin is on a separate line.
    $reset();
    $plugin::register_footer_credit();
    check( 0 === $settings::$reads, 'Legacy setting unexpectedly consulted' );
    foreach ( array( 'generate_credits', 'wp_footer', 'wp_enqueue_scripts' ) as $hook ) {
        check( count( $GLOBALS['aikey_test_hooks'][ $hook ] ?? array() ) === 1, 'Missing hook: ' . $hook );
    }
    check( ! isset( $GLOBALS['aikey_test_hooks']['generate_before_footer_content'] ), 'Wrong early footer hook remains' );
    check( ! isset( $GLOBALS['aikey_test_hooks']['generate_after_footer_content'] ), 'Wrong outer footer hook remains' );
    check( ! isset( $GLOBALS['aikey_test_hooks']['generate_copyright'] ), 'Copyright filter must not be replaced' );
    check( PHP_INT_MAX === $GLOBALS['aikey_test_hooks']['generate_credits'][0]['priority'], 'Credit must follow existing GeneratePress credits' );

    add_action( 'generate_credits', static function (): void {
        echo '<div class="copyright">© 2026 Original owner · ICP and police registration · Built with theme</div>';
    }, 10 );
    ob_start();
    echo '<footer class="site-info"><div class="inside-site-info">';
    do_action( 'generate_credits' );
    echo '</div></footer>';
    do_action( 'wp_footer' );
    $html = ob_get_clean();
    check( 1 === substr_count( $html, 'class="aikey-seo-geo-footer-credit"' ), 'GeneratePress credit is missing or duplicated' );
    check( strpos( $html, 'Original owner' ) < strpos( $html, 'class="aikey-seo-geo-footer-credit"' ), 'Credit must be after existing copyright' );
    check( strpos( $html, 'police registration' ) < strpos( $html, 'class="aikey-seo-geo-footer-credit"' ), 'Credit must follow registration details' );
    check( strpos( $html, 'class="aikey-seo-geo-footer-credit"' ) < strpos( $html, '</div></footer>' ), 'Credit must be INSIDE copyright footer module' );
    check( str_contains( $html, 'Built with theme' ), 'Original credits were replaced' );
    check( ! str_contains( $html, 'id="aikey-seo-geo-footer-credit-fallback"' ), 'GeneratePress rendered a needless fallback script' );
    check( str_contains( $html, 'href="https://aikeyboost.com/" rel="nofollow"' ), 'Missing attribution link' );

    do_action( 'wp_enqueue_scripts' );
    check( 1 === count( $GLOBALS['aikey_test_styles'] ), 'Footer CSS was not enqueued' );
    check( '2.3.11' === $GLOBALS['aikey_test_styles'][0]['version'], 'Plugin version changed unexpectedly' );

    $css = file_get_contents( dirname( __DIR__ ) . '/aikey-seo-geo/assets/css/footer-credit.css' );
    check( str_contains( $css, 'background: transparent' ), 'Standalone footer background is still present' );
    check( ! str_contains( $css, 'position: fixed' ) && ! str_contains( $css, 'position: absolute' ), 'Credit must stay in document flow' );

    // Other themes: the fallback only inserts into an existing footer node,
    // never creates a separate visible block after the closing theme footer.
    $reset();
    $plugin::register_footer_credit();
    ob_start();
    do_action( 'wp_footer' );
    do_action( 'wp_footer' );
    $fallback = ob_get_clean();
    check( 1 === substr_count( $fallback, 'id="aikey-seo-geo-footer-credit-fallback"' ), 'Fallback must be emitted once' );
    check( str_contains( $fallback, "target.appendChild(block)" ), 'Fallback must insert inside existing footer' );
    check( str_contains( $fallback, "if (!target) { return; }" ), 'Fallback must not create a standalone module' );
    check( ! str_contains( $fallback, '<div class="aikey-seo-geo-footer-credit">' ), 'Fallback added a visible outer block' );

    // Backend should have no public hooks or output even if invoked manually.
    $reset();
    $GLOBALS['aikey_test_is_admin'] = true;
    $plugin::register_footer_credit();
    check( ! $GLOBALS['aikey_test_hooks'], 'Admin request registered frontend hooks' );
    ob_start(); $plugin::render_footer_credit();
    check( '' === ob_get_clean(), 'Admin request emitted credit markup' );
    $GLOBALS['aikey_test_is_admin'] = false;

    // The deprecated UI checkbox is absent from the HTML and save handler.
    $admin_src = file_get_contents( dirname( __DIR__ ) . '/aikey-seo-geo/src/Admin/Admin.php' );
    $plugin_src = file_get_contents( dirname( __DIR__ ) . '/aikey-seo-geo/src/Core/Plugin.php' );
    $settings_src = file_get_contents( dirname( __DIR__ ) . '/aikey-seo-geo/src/Core/Settings.php' );
    check( ! str_contains( $admin_src, 'name="show_footer_credit"' ), 'Admin checkbox remains visible' );
    check( ! str_contains( $admin_src, '$settings[\'show_footer_credit\']' ), 'Admin still persists the obsolete checkbox' );
    check( ! str_contains( $plugin_src, 'Settings::get( \'show_footer_credit\'' ), 'Old setting still controls output' );
    check( ! str_contains( $settings_src, "'show_footer_credit'" ), 'Obsolete setting still a default' );

    echo 'Mandatory credit regression PASS: ' . $GLOBALS['aikey_assertions'] . " checks\n";
}
