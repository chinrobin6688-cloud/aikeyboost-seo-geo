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

    // Existing saved options must not affect the GitHub edition credit.
    $reset();
    $plugin::register_footer_credit();
    check( 0 === $settings::$reads, 'Legacy setting unexpectedly consulted' );
    foreach ( array( 'wp_footer', 'wp_enqueue_scripts' ) as $hook ) {
        check( count( $GLOBALS['aikey_test_hooks'][ $hook ] ?? array() ) === 1, 'Missing hook: ' . $hook );
    }
    check( ! isset( $GLOBALS['aikey_test_hooks']['generate_before_footer_content'] ), 'Wrong early GeneratePress hook remains' );
    check( ! isset( $GLOBALS['aikey_test_hooks']['generate_after_footer'] ), 'Theme-specific hook must not render credit' );
    check( ! isset( $GLOBALS['aikey_test_hooks']['generate_copyright'] ), 'Theme copyright filter registered' );
    check( ! isset( $GLOBALS['aikey_test_hooks']['widget_block_content'] ), 'Widget content filter registered' );
    check( PHP_INT_MAX === $GLOBALS['aikey_test_hooks']['wp_footer'][0]['priority'], 'Credit must run last in wp_footer' );

    // Both theme footer content and copyright / ICP-style notices must precede credit.
    add_action( 'wp_footer', static function(): void {
        echo '<span class="theme-credit">Original theme copyright</span>';
        echo '<span class="icp-notice">ICP registration information</span>';
    }, 20 );
    ob_start();
    echo '<footer>Business name, address and footer navigation</footer>';
    do_action( 'wp_footer' );
    $html = ob_get_clean();
    check( substr_count( $html, 'class="aikey-seo-geo-footer-credit"' ) === 1, 'Duplicate credit' );
    check( strpos( $html, 'Business name' ) < strpos( $html, 'aikey-seo-geo-footer-credit' ), 'Credit appeared above theme footer' );
    check( strpos( $html, 'Original theme copyright' ) < strpos( $html, 'aikey-seo-geo-footer-credit' ), 'Credit appeared above theme copyright' );
    check( strpos( $html, 'ICP registration information' ) < strpos( $html, 'aikey-seo-geo-footer-credit' ), 'Credit appeared above ICP notice' );
    check( str_contains( $html, 'Original theme copyright' ), 'Theme attribution was erased' );
    check( str_contains( $html, 'ICP registration information' ), 'ICP notice was erased' );
    check( str_contains( $html, 'href="https://aikeyboost.com/" rel="nofollow"' ), 'Missing nofollow credit link' );

    do_action( 'wp_enqueue_scripts' );
    check( 1 === count( $GLOBALS['aikey_test_styles'] ), 'Frontend style not enqueued' );
    check( $GLOBALS['aikey_test_styles'][0]['version'] === '2.3.11', 'Stale CSS cache version' );

    // Generic themes: the standard wp_footer callback still works once.
    $reset();
    $plugin::register_footer_credit();
    ob_start(); do_action( 'wp_footer' ); do_action( 'wp_footer' );
    $html=ob_get_clean();
    check( 1 === substr_count( $html, 'class="aikey-seo-geo-footer-credit"' ), 'Generic theme doubled attribution' );
    check( 1 === substr_count( $html, 'href="https://aikeyboost.com/"' ), 'Generic theme lost attribution' );

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
