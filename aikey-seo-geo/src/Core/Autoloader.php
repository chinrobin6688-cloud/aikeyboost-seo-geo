<?php
namespace AIKEY\SeoGeo\Core;

defined( 'ABSPATH' ) || exit;

final class Autoloader {
    private const PREFIX = 'AIKEY\\SeoGeo\\';

    public static function register(): void {
        spl_autoload_register( array( self::class, 'autoload' ) );
    }

    private static function autoload( string $class ): void {
        if ( 0 !== strpos( $class, self::PREFIX ) ) {
            return;
        }

        $relative = substr( $class, strlen( self::PREFIX ) );
        $path     = AIKEY_SEO_GEO_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

        if ( is_readable( $path ) ) {
            require_once $path;
        }
    }
}
