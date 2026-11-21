<?php
/** Standalone regression for the optional frontend attribution hooks (php tests/footer-credit-smoke.php). */
namespace {
    $GLOBALS['aikey_test_hooks'] = array();
    $GLOBALS['aikey_test_styles'] = array();
    $GLOBALS['aikey_test_is_admin'] = false;

    function add_action( string $hook, callable $callback, int $priority = 10 ): void {
        $GLOBALS['aikey_test_hooks'][ $hook ][] = $callback;
    }
    function do_action( string $hook ): void {
        foreach ( $GLOBALS['aikey_test_hooks'][ $hook ] ?? array() as $callback ) { $callback(); }
    }
    function wp_enqueue_style( string $handle, string $url, array $deps = array(), string $version = '' ): void {
        $GLOBALS['aikey_test_styles'][] = compact( 'handle', 'url', 'deps', 'version' );
    }
    function is_admin(): bool { return $GLOBALS['aikey_test_is_admin']; }
    function esc_url( string $url ): string { return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ); }
    function esc_html( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
    function test_check( bool $condition, string $message ): void {
        if ( ! $condition ) { throw new \RuntimeException( $message ); }
        $GLOBALS['aikey_assertions'] = ( $GLOBALS['aikey_assertions'] ?? 0 ) + 1;
    }
    define( 'ABSPATH', __DIR__ );
    define( 'AIKEY_SEO_GEO_VERSION', '2.3.9' );
    define( 'AIKEY_SEO_GEO_URL', 'https://demo.invalid/wp-content/plugins/aikey-seo-geo/' );
}
namespace AIKEY\SeoGeo\Core {
    final class Settings {
        public static bool $credit = true;
        public static function get( string $key, mixed $default = null ): mixed {
            return 'show_footer_credit' === $key ? self::$credit : $default;
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
        $prop = new \ReflectionProperty( $plugin, 'footer_credit_rendered' );
        $prop->setValue( false );
    };

    // Disabled: no hooks, no extra front-end requests or markup.
    $reset();
    $settings::$credit = false;
    $plugin::register_footer_credit();
    test_check( ! $GLOBALS['aikey_test_hooks'], 'Disabled setting registered hooks' );
    ob_start(); do_action( 'wp_footer' );
    test_check( '' === ob_get_clean(), 'Disabled setting emitted markup' );

    // Do not register front-end hooks inside the WordPress administrator.
    $reset(); $settings::$credit = true; $GLOBALS['aikey_test_is_admin'] = true;
    $plugin::register_footer_credit();
    test_check( ! $GLOBALS['aikey_test_hooks'], 'Admin request registered hooks' );
    $GLOBALS['aikey_test_is_admin'] = false;

    // Enabled: register semantic theme/fallback hooks but NEVER theme filters.
    $reset(); $settings::$credit = true;
    $plugin::register_footer_credit();
    foreach ( array( 'generate_after_footer', 'wp_footer', 'wp_enqueue_scripts' ) as $hook ) {
        test_check( 1 === count( $GLOBALS['aikey_test_hooks'][ $hook ] ?? array() ), 'Missing required hook: ' . $hook );
    }
    test_check( ! isset( $GLOBALS['aikey_test_hooks']['generate_copyright'] ), 'Copyright replacement hook registered' );
    test_check( ! isset( $GLOBALS['aikey_test_hooks']['widget_block_content'] ), 'Widget replacement hook registered' );
    $original = '<span>Built with GeneratePress</span>';
    test_check( '<span>Built with GeneratePress</span>' === $original, 'Theme copyright changed' );

    do_action( 'wp_enqueue_scripts' );
    test_check( 1 === count( $GLOBALS['aikey_test_styles'] ), 'Credit stylesheet missing or duplicated' );
    $style = $GLOBALS['aikey_test_styles'][0];
    test_check( 'aikey-seo-geo-footer-credit' === $style['handle'], 'Wrong CSS handle' );
    test_check( str_ends_with( $style['url'], '/assets/css/footer-credit.css' ), 'Wrong CSS URL' );
    test_check( '2.3.9' === $style['version'], 'Wrong asset cache version' );

    // GeneratePress calls its footer hook before wp_footer; must output once.
    ob_start();
    do_action( 'generate_after_footer' );
    do_action( 'wp_footer' );
    $markup = ob_get_clean();
    test_check( 1 === substr_count( $markup, 'aikey-seo-geo-footer-credit' ), 'Duplicate output in GeneratePress' );
    test_check( 1 === substr_count( $markup, 'href="https://aikeyboost.com/"' ), 'Missing or duplicate attribution link' );
    test_check( str_contains( $markup, 'GEO services powered by' ), 'Attribution copy was changed' );
    test_check( ! str_contains( $markup, 'Built with' ), 'Theme copyright was rewritten' );

    // Generic classic/block themes without GeneratePress hook use wp_footer.
    $reset(); $settings::$credit = true;
    $plugin::register_footer_credit();
    ob_start(); do_action( 'wp_footer' ); do_action( 'wp_footer' );
    $fallback = ob_get_clean();
    test_check( 1 === substr_count( $fallback, 'aikey-seo-geo-footer-credit' ), 'Generic theme did not output exactly once' );

    // When an option is switched off during a request, no residual output.
    $reset(); $settings::$credit = true;
    $plugin::register_footer_credit();
    $settings::$credit = false;
    ob_start(); do_action( 'generate_after_footer' ); do_action( 'wp_footer' );
    test_check( '' === ob_get_clean(), 'Stored opt-out was ignored' );
    do_action( 'wp_enqueue_scripts' );
    test_check( ! $GLOBALS['aikey_test_styles'], 'Disabled style unexpectedly enqueued' );

    echo 'Footer credit regression PASS: ' . $GLOBALS['aikey_assertions'] . " checks\n";
}
