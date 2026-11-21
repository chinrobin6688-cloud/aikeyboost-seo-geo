<?php
namespace AIKEY\SeoGeo\Support;

defined( 'ABSPATH' ) || exit;

final class Utils {
    public static function public_post_types(): array {
        $types = get_post_types( array( 'public' => true ), 'names' );
        unset( $types['attachment'] );
        return array_values( $types );
    }

    public static function normalize_path( string $path ): string {
        $path = wp_parse_url( $path, PHP_URL_PATH ) ?: $path;
        $path = '/' . ltrim( rawurldecode( (string) $path ), '/' );
        if ( '/' !== $path ) { $path = untrailingslashit( $path ); }
        return substr( sanitize_text_field( $path ), 0, 191 );
    }

    public static function text_length( string $text ): int {
        $text = trim( wp_strip_all_tags( $text ) );
        return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
    }

    public static function clean_lines( string $text, int $limit = 100 ): array {
        $lines = preg_split( '/\r\n|\r|\n/', $text ) ?: array();
        $out = array();
        foreach ( $lines as $line ) {
            $line = trim( sanitize_text_field( $line ) );
            if ( '' !== $line ) { $out[] = $line; }
            if ( count( $out ) >= $limit ) { break; }
        }
        return $out;
    }

    public static function current_path(): string {
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
        return self::normalize_path( (string) $uri );
    }
}
