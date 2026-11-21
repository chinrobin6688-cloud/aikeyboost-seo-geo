<?php
namespace AIKEY\SeoGeo\SEO\Schema;

use AIKEY\SeoGeo\GEO\Language;

defined( 'ABSPATH' ) || exit;

/** Validated, explicitly configured organization details for each public locale. */
final class Localized_Entity {
    private const MAX_ROWS = 40;

    /**
     * One locale per line: locale | name | address | description | service area.
     * Reject invalid input instead of silently dropping previously saved records.
     *
     * @return array|\WP_Error
     */
    public static function parse( string $text ) {
        $rows = preg_split( '/\r\n|\r|\n/', $text );
        if ( ! is_array( $rows ) ) { return new \WP_Error( 'invalid_localizations', '多语言企业资料无法解析。' ); }
        $result = array();
        $filled = array_values( array_filter( $rows, static fn( $row ) => '' !== trim( $row ) ) );
        if ( count( $filled ) > self::MAX_ROWS ) {
            return new \WP_Error( 'too_many_localizations', '最多配置40种语言的企业资料。' );
        }
        foreach ( $filled as $row ) {
            $parts = array_map( 'trim', explode( '|', $row ) );
            if ( count( $parts ) !== 5 ) {
                return new \WP_Error( 'invalid_localization', '多语言企业资料必须每行填写语言代码及4项企业信息。' );
            }
            $locale = Language::normalize( $parts[0] );
            if ( '' === $locale || isset( $result[ $locale ] ) ) {
                return new \WP_Error( 'duplicate_locale', '语言代码无效或重复。' );
            }
            $values = array_map( 'sanitize_text_field', array_slice( $parts, 1 ) );
            foreach ( array( 200, 300, 500, 200 ) as $index => $limit ) {
                if ( '' === $values[ $index ] || strlen( $values[ $index ] ) > $limit * 4 ) {
                    return new \WP_Error( 'invalid_entity_field', '企业资料不能为空或过长。' );
                }
            }
            $result[ $locale ] = array(
                'name' => $values[0],
                'address' => $values[1],
                'description' => $values[2],
                'areaServed' => $values[3],
            );
        }
        return $result;
    }

    public static function format( array $records ): string {
        $lines = array();
        foreach ( $records as $locale => $record ) {
            if ( ! is_array( $record ) ) { continue; }
            $lines[] = implode( ' | ', array(
                $locale, $record['name'] ?? '', $record['address'] ?? '',
                $record['description'] ?? '', $record['areaServed'] ?? '',
            ) );
        }
        return implode( "\n", $lines );
    }

    /** Exact language first, then primary language; never fabricate translations. */
    public static function for_language( array $records, string $language ): array {
        $locale = Language::normalize( $language );
        if ( '' === $locale ) { return array(); }
        if ( isset( $records[ $locale ] ) && is_array( $records[ $locale ] ) ) {
            return $records[ $locale ];
        }
        $primary = explode( '-', $locale, 2 )[0];
        return isset( $records[ $primary ] ) && is_array( $records[ $primary ] )
            ? $records[ $primary ] : array();
    }
}
