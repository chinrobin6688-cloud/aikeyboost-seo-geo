<?php
namespace AIKEY\SeoGeo\Core;

defined( 'ABSPATH' ) || exit;

final class Database {
    public static function table( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . 'aikey_' . $name;
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $redirects = self::table( 'redirects' );
        $logs      = self::table( '404_logs' );
        $refs      = self::table( 'referrals' );
        $crawlers  = self::table( 'crawler_logs' );
        $submissions = self::table( 'submission_logs' );
        $contacts    = self::table( 'contact_messages' );
        $translations = self::table( 'translation_members' );

        dbDelta( "CREATE TABLE {$redirects} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_path varchar(191) NOT NULL,
            target_url text NOT NULL,
            status smallint(5) unsigned NOT NULL DEFAULT 301,
            enabled tinyint(1) NOT NULL DEFAULT 1,
            hits bigint(20) unsigned NOT NULL DEFAULT 0,
            last_hit datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_path (source_path),
            KEY enabled (enabled)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$logs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            path varchar(191) NOT NULL,
            referrer text NULL,
            hits bigint(20) unsigned NOT NULL DEFAULT 1,
            first_seen datetime NOT NULL,
            last_seen datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY path (path),
            KEY last_seen (last_seen)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$refs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source varchar(50) NOT NULL,
            path varchar(191) NOT NULL,
            hits bigint(20) unsigned NOT NULL DEFAULT 1,
            first_seen datetime NOT NULL,
            last_seen datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_path (source,path),
            KEY last_seen (last_seen)
        ) {$charset};" );


        dbDelta( "CREATE TABLE {$crawlers} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source varchar(80) NOT NULL,
            crawler_type varchar(20) NOT NULL,
            path varchar(191) NOT NULL,
            hits bigint(20) unsigned NOT NULL DEFAULT 1,
            first_seen datetime NOT NULL,
            last_seen datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_path (source,path),
            KEY crawler_type (crawler_type),
            KEY last_seen (last_seen)
        ) {$charset};" );




        dbDelta( "CREATE TABLE {$contacts} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL DEFAULT '',
            form_id varchar(40) NOT NULL DEFAULT '',
            phone varchar(60) NOT NULL DEFAULT '',
            email varchar(191) NOT NULL DEFAULT '',
            message text NULL,
            source_url text NULL,
            is_read tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY is_read (is_read),
            KEY created_at (created_at)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$submissions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            channel varchar(40) NOT NULL,
            item_count int(10) unsigned NOT NULL DEFAULT 0,
            status_code smallint(5) unsigned NOT NULL DEFAULT 0,
            success tinyint(1) NOT NULL DEFAULT 0,
            message varchar(255) NOT NULL DEFAULT '',
            sample_url text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY channel (channel),
            KEY success (success),
            KEY created_at (created_at)
        ) {$charset};" );
        dbDelta( "CREATE TABLE {$translations} (
            post_id bigint(20) unsigned NOT NULL,
            group_key varchar(36) NOT NULL,
            language varchar(35) NOT NULL,
            PRIMARY KEY  (post_id),
            UNIQUE KEY group_language (group_key,language),
            KEY language (language)
        ) {$charset};" );

        self::verify_installed_schema();
    }

    /** Abort failed/partial dbDelta runs instead of recording an unusable DB version. */
    private static function verify_installed_schema(): void {
        global $wpdb;
        $required = array(
            'redirects'        => array( 'id', 'source_path', 'target_url', 'status', 'enabled', 'hits', 'last_hit', 'created_at', 'updated_at' ),
            '404_logs'         => array( 'id', 'path', 'referrer', 'hits', 'first_seen', 'last_seen' ),
            'referrals'        => array( 'id', 'source', 'path', 'hits', 'first_seen', 'last_seen' ),
            'crawler_logs'     => array( 'id', 'source', 'crawler_type', 'path', 'hits', 'first_seen', 'last_seen' ),
            'contact_messages' => array( 'id', 'name', 'form_id', 'phone', 'email', 'message', 'source_url', 'is_read', 'created_at' ),
            'submission_logs'  => array( 'id', 'channel', 'item_count', 'status_code', 'success', 'message', 'sample_url', 'created_at' ),
            'translation_members'=>array( 'post_id','group_key','language' ),
        );
        foreach ( $required as $suffix => $columns ) {
            // The identifier is made only from the WordPress DB prefix and fixed names.
            $table = str_replace( '`', '``', self::table( $suffix ) );
            $existing = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( ! is_array( $existing ) || array_diff( $columns, $existing ) ) {
                throw new \RuntimeException( 'Aikeyboost SEO&GEO 数据库表或字段校验失败：' . $suffix . '。迁移版本不会推进。' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal error message, not rendered HTML; callers escape before display.
            }
        }
        // These unique constraints are the core translation-group integrity guarantees.
        $table = str_replace( '`', '``', self::table( 'translation_members' ) );
        $indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
        $unique = array();
        foreach ( is_array( $indexes ) ? $indexes : array() as $row ) {
            if ( (int) $row['Non_unique'] === 0 ) {
                $unique[ (string) $row['Key_name'] ][ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
            }
        }
        if ( ( $unique['PRIMARY'][1] ?? '' ) !== 'post_id'
            || ( $unique['group_language'][1] ?? '' ) !== 'group_key'
            || ( $unique['group_language'][2] ?? '' ) !== 'language' ) {
            throw new \RuntimeException( '翻译组数据库唯一索引缺失，迁移版本不会推进。' );
        }
    }
}
