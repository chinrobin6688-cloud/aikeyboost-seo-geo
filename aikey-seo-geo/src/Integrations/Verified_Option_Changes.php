<?php
namespace AIKEY\SeoGeo\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Verified, best-effort rollback for the small collection of provider options.
 *
 * WordPress options have no general multi-row transaction. On write failure
 * restore the previous values; callers must still fail closed by clearing
 * active credentials if an external storage backend cannot complete rollback.
 */
final class Verified_Option_Changes {
    private const ALLOWED = array(
        'aikey_gsc_client_id',
        'aikey_gsc_client_secret_encrypted',
        'aikey_gsc_property',
        'aikey_seo_geo_bing_webmaster_key',
        'aikey_seo_geo_bing_webmaster_site',
    );

    public static function apply( array $changes ): bool {
        if ( ! $changes ) { return true; }
        foreach ( $changes as $name => $value ) {
            if ( ! in_array( $name, self::ALLOWED, true ) || ! is_string( $value ) ) {
                return false;
            }
        }

        $original = array();
        foreach ( $changes as $name => $value ) {
            // The managed values are always strings. null marks an absent option.
            $original[ $name ] = get_option( $name, null );
        }
        $attempted = array();
        foreach ( $changes as $name => $value ) {
            $attempted[] = $name;
            update_option( $name, $value, false );
            if ( get_option( $name, null ) !== $value ) {
                // Include the failed key: persistent-cache/database backends can
                // report a failure after partially applying a change.
                foreach ( array_reverse( $attempted ) as $rollback ) {
                    if ( null === $original[ $rollback ] ) {
                        delete_option( $rollback );
                    } else {
                        update_option( $rollback, $original[ $rollback ], false );
                    }
                }
                return false;
            }
        }
        return true;
    }
}
