<?php
namespace AIKEY\SeoGeo\Contacts;

use AIKEY\SeoGeo\Core\Database;

defined( 'ABSPATH' ) || exit;

final class Contact_Repository {
    public static function add( array $fields, string $source_url, string $form_id = '' ): int {
        global $wpdb;
        $ok = $wpdb->insert(
            Database::table( 'contact_messages' ),
            array(
                'name'       => (string) ( $fields['name'] ?? '' ),
                'form_id'    => $form_id,
                'phone'      => (string) ( $fields['phone'] ?? '' ),
                'email'      => (string) ( $fields['email'] ?? '' ),
                'message'    => (string) ( $fields['message'] ?? '' ),
                'source_url' => $source_url,
                'is_read'    => 0,
                'created_at' => current_time( 'mysql', true ),
            ),
            array( '%s','%s','%s','%s','%s','%s','%d','%s' )
        );
        return false === $ok ? 0 : (int) $wpdb->insert_id;
    }


    public static function recent( int $limit = 100 ): array {
        global $wpdb;
        $limit = min( 500, max( 1, $limit ) );
        return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', Database::table( 'contact_messages' ), $limit ) );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        return false !== $wpdb->delete( Database::table( 'contact_messages' ), array( 'id' => $id ), array( '%d' ) );
    }

    public static function unread_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::table( 'contact_messages' ) . ' WHERE is_read=0' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function total_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::table( 'contact_messages' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function mark_all_read(): void {
        global $wpdb;
        $wpdb->query( 'UPDATE ' . Database::table( 'contact_messages' ) . ' SET is_read=1 WHERE is_read=0' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }
}
