<?php
/** Standalone regression: the attribution remains inline with existing credits. */
namespace {
    $GLOBALS['aikey_test_hooks'] = array();
    $GLOBALS['aikey_admin'] = false;
    $GLOBALS['aikey_assertions'] = 0;

    function add_action( string $hook, callable $callback, int $priority = 10 ): void {
        $GLOBALS['aikey_test_hooks'][ $hook ][] = array(
            'callback' => $callback,
            'priority' => $priority,
        );
    }
    function do_action( string $hook ): void {
        $callbacks = $GLOBALS['aikey_test_hooks'][ $hook ] ?? array();
        usort( $callbacks, static fn( $a, $b ) => $a['priority'] <=> $b['priority'] );
        foreach ( $callbacks as $item ) { ( $item['callback'] )(); }
    }
    function is_admin(): bool { return $GLOBALS['aikey_admin']; }
    function esc_url( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
    function esc_html( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
    function check( bool $condition, string $error ): void {
        if ( ! $condition ) { throw new \RuntimeException( $error ); }
        ++$GLOBALS['aikey_assertions'];
    }

    define( 'ABSPATH', __DIR__ );
    define( 'AIKEY_SEO_GEO_VERSION', '2.3.11' );

    require dirname( __DIR__ ) . '/aikey-seo-geo/src/Core/Plugin.php';
    $plugin = \AIKEY\SeoGeo\Core\Plugin::class;

    $reset = static function () use ( $plugin ): void {
        $GLOBALS['aikey_test_hooks'] = array();
        $credit_flag = new \ReflectionProperty( $plugin, 'footer_credit_rendered' );
        $credit_flag->setAccessible( true ); // PHP 8.0 requires access to private properties.
        $credit_flag->setValue( false );
    };

    // GeneratePress: append an inline span after the existing copyright and ICP.
    $reset();
    $plugin::register_footer_credit();
    check( count( $GLOBALS['aikey_test_hooks']['generate_credits'] ?? array() ) === 1, 'GeneratePress credits hook missing' );
    check( count( $GLOBALS['aikey_test_hooks']['wp_footer'] ?? array() ) === 1, 'Safe fallback missing' );
    check( ! isset( $GLOBALS['aikey_test_hooks']['wp_enqueue_scripts'] ), 'Obsolete footer stylesheet still enqueued' );
    check( ! isset( $GLOBALS['aikey_test_hooks']['generate_before_footer_content'] ), 'Obsolete early footer hook registered' );
    check( ! isset( $GLOBALS['aikey_test_hooks']['generate_after_footer'] ), 'Obsolete after-footer hook registered' );
    check( ! isset( $GLOBALS['aikey_test_hooks']['generate_copyright'] ), 'Existing copyright must not be replaced' );
    check( PHP_INT_MAX === $GLOBALS['aikey_test_hooks']['generate_credits'][0]['priority'], 'Credit not appended last' );

    add_action( 'generate_credits', static function (): void {
        echo '<span class="copyright-bar">© 2026 Owner · ICP registration · Built with Owner</span>';
    }, 10 );
    ob_start();
    echo '<footer class="site-info"><div class="inside-site-info">';
    do_action( 'generate_credits' );
    echo '</div></footer>';
    do_action( 'wp_footer' );
    $html = ob_get_clean();

    check( substr_count( $html, 'class="aikey-seo-geo-inline-credit"' ) === 1, 'Inline credit missing or duplicated' );
    check( str_contains( $html, 'Built with Owner</span> <span class="aikey-seo-geo-inline-credit">' ), 'Credit did not follow existing line inline' );
    check( str_contains( $html, '&middot; GEO services powered by ' ), 'Missing inline separator' );
    check( str_contains( $html, 'href="https://aikeyboost.com/" rel="nofollow"' ), 'Credit link missing' );
    check( str_contains( $html, '© 2026 Owner · ICP registration' ), 'Copyright or ICP content overwritten' );
    check( substr_count( $html, '<div' ) === 1, 'Standalone footer div was created' );
    check( strpos( $html, 'aikey-seo-geo-inline-credit' ) < strpos( $html, '</div></footer>' ), 'Credit rendered outside theme footer' );
    check( ! str_contains( $html, '<script' ), 'Fallback must not run when GeneratePress output exists' );

    // Generic WordPress theme: fallback injects ONLY inside an existing copyright line.
    $reset();
    $plugin::register_footer_credit();
    ob_start();
    do_action( 'wp_footer' );
    do_action( 'wp_footer' );
    $fallback = ob_get_clean();
    check( substr_count( $fallback, 'id="aikey-seo-geo-credit-fallback"' ) === 1, 'Fallback script duplicated' );
    check( str_contains( $fallback, "document.querySelector(" ), 'Fallback must find a copyright container' );
    check( str_contains( $fallback, "if (!target) { return; }" ), 'Fallback must not create an independent footer' );
    check( str_contains( $fallback, "document.createElement('span')" ), 'Fallback must use inline text' );
    check( str_contains( $fallback, "target.appendChild(span)" ), 'Fallback does not append inline' );
    check( ! str_contains( $fallback, "document.createElement('div')" ), 'Fallback creates another footer module' );

    // The backend never registers or prints public attribution.
    $reset();
    $GLOBALS['aikey_admin'] = true;
    $plugin::register_footer_credit();
    check( ! $GLOBALS['aikey_test_hooks'], 'Admin registered public hooks' );
    ob_start();
    $plugin::render_footer_credit();
    $plugin::render_footer_credit_fallback();
    check( '' === ob_get_clean(), 'Admin output visible credit' );
    $GLOBALS['aikey_admin'] = false;

    // Keep admin UI and settings clean, and remove obsolete footer CSS.
    $admin = file_get_contents( dirname( __DIR__ ) . '/aikey-seo-geo/src/Admin/Admin.php' );
    $src = file_get_contents( dirname( __DIR__ ) . '/aikey-seo-geo/src/Core/Plugin.php' );
    check( ! str_contains( $admin, 'name="show_footer_credit"' ), 'Obsolete admin checkbox remains' );
    check( ! str_contains( $src, 'footer-credit.css' ), 'Deleted CSS referenced in production code' );
    check( ! file_exists( dirname( __DIR__ ) . '/aikey-seo-geo/assets/css/footer-credit.css' ), 'Obsolete CSS shipped' );
    echo 'Inline footer credit regression PASS: ' . $GLOBALS['aikey_assertions'] . " checks\n";
}
