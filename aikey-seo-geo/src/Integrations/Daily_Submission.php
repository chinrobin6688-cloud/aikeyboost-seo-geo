<?php
namespace AIKEY\SeoGeo\Integrations;

use AIKEY\SeoGeo\Core\Conflict_Detector;
use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\SEO\Meta\Meta_Repository;
use AIKEY\SeoGeo\SEO\Sitemap\Sitemap_Content;
use AIKEY\SeoGeo\Support\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Daily incremental submission backstop.
 *
 * Real-time publish/update hooks remain in Baidu_Module and IndexNow_Module. This
 * service runs once per day and only retries/newly submits published, indexable
 * URLs whose current post_modified_gmt signature has not already been marked as
 * successfully submitted for the respective channel.
 */
final class Daily_Submission {
    public const CRON = 'aikey_seo_geo_daily_incremental_submission';
    private const LAST_RUN_OPTION = 'aikey_seo_geo_daily_submission_last_run';
    private const LAST_STATS_OPTION = 'aikey_seo_geo_daily_submission_last_stats';
    private const DIRTY_META = '_aikey_submission_dirty_sig';

    public static function register_hooks(): void {
        add_action( 'init', array( self::class, 'ensure_schedule' ), 30 );
        add_action( self::CRON, array( self::class, 'cron_run' ) );
    }

    public static function ensure_schedule(): void {
        if ( ! Settings::section_get( 'submission', 'daily_incremental', false ) ) {
            wp_clear_scheduled_hook( self::CRON );
            return;
        }
        if ( ! wp_next_scheduled( self::CRON ) ) {
            // WP-Cron is traffic-driven; this means "about daily", not an exact wall-clock SLA.
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
        }
    }

    public static function cron_run(): void {
        self::run( false );
    }

    public static function run( bool $manual = false ): array {
        $limit = min( 500, max( 1, (int) Settings::section_get( 'submission', 'daily_limit', 19 ) ) );
        $stats = array(
            'manual'      => $manual,
            'ran_at'      => current_time( 'mysql' ),
            'indexnow'    => array( 'status'=>'skipped', 'pending'=>0, 'submitted'=>0, 'message'=>'' ),
            'baidu'       => array( 'status'=>'skipped', 'pending'=>0, 'submitted'=>0, 'message'=>'' ),
        );

        if ( ! (bool) get_option( 'blog_public', true ) ) {
            $stats['indexnow']['message'] = '站点设置为不允许搜索引擎索引。';
            $stats['baidu']['message'] = '站点设置为不允许搜索引擎索引。';
            return self::finish( $stats );
        }
        if ( ! Conflict_Detector::frontend_allowed() ) {
            $stats['indexnow']['message'] = 'SEO 前台接管未启用。';
            $stats['baidu']['message'] = 'SEO 前台接管未启用。';
            return self::finish( $stats );
        }

        if ( Settings::module_enabled( 'indexnow' ) ) {
            $pending = self::pending_posts( 'indexnow', $limit );
            $stats['indexnow']['pending'] = count( $pending );
            if ( $pending ) {
                $urls = array_values( array_map( static fn( array $row ): string => $row['url'], $pending ) );
                $result = IndexNow_Module::submit_urls( $urls, 'daily_incremental' );
                if ( is_wp_error( $result ) ) {
                    $stats['indexnow']['status'] = 'error';
                    $stats['indexnow']['message'] = $result->get_error_message();
                } else {
                    $submitted = (int) ( $result['success'] ?? 0 );
                    $stats['indexnow']['submitted'] = $submitted;
                    $stats['indexnow']['status'] = 'success';
                    $stats['indexnow']['message'] = '每日增量提交完成。';
                    if ( $submitted === count( $pending ) ) {
                        self::mark_submitted( $pending, IndexNow_Module::LAST_SIG );
                    }
                }
            } else {
                $stats['indexnow']['status'] = 'idle';
                $stats['indexnow']['message'] = '没有尚未成功提交的新建或更新 URL。';
            }
        } else {
            $stats['indexnow']['message'] = 'IndexNow 模块已关闭。';
        }

        $baidu = Settings::section( 'baidu' );
        if ( Settings::module_enabled( 'baidu' ) && ! empty( $baidu['site'] ) && ! empty( $baidu['token'] ) ) {
            if ( Baidu_Submitter::quota_paused_today() ) {
                $stats['baidu']['status'] = 'quota';
                $stats['baidu']['message'] = '百度今日主动提交配额已用完，已暂停到次日自动重试。';
            } else {
                $baidu_limit = min( Baidu_Submitter::SAFE_BATCH_LIMIT, $limit );
                $pending = self::pending_posts( 'baidu', $baidu_limit );
                $stats['baidu']['pending'] = count( $pending );
                if ( $pending ) {
                    // Quota-friendly strategy: probe with exactly one URL first. A successful
                    // Baidu response tells us the remaining quota, so the second request can be
                    // safely capped instead of blindly sending a large batch and triggering
                    // `over quota` before any URL is accepted.
                    $probe = array( $pending[0] );
                    $result = Baidu_Submitter::submit_urls( array( $probe[0]['url'] ), 'daily_incremental_probe' );
                    if ( is_wp_error( $result ) ) {
                        $stats['baidu']['status'] = 'baidu_over_quota' === $result->get_error_code() || 'baidu_over_quota_cached' === $result->get_error_code() ? 'quota' : 'error';
                        $stats['baidu']['message'] = $result->get_error_message();
                    } else {
                        $probe_success = (int) ( $result['success'] ?? 0 );
                        $submitted_total = $probe_success;
                        if ( 1 === $probe_success ) { self::mark_submitted( $probe, Baidu_Module::LAST_SIG ); }
                        $remain = max( 0, (int) ( $result['remain'] ?? 0 ) );
                        $rest = array_slice( $pending, 1, min( $remain, max( 0, $baidu_limit - 1 ) ) );
                        if ( $rest ) {
                            $rest_urls = array_values( array_map( static fn( array $row ): string => $row['url'], $rest ) );
                            $second = Baidu_Submitter::submit_urls( $rest_urls, 'daily_incremental' );
                            if ( is_wp_error( $second ) ) {
                                $stats['baidu']['status'] = in_array( $second->get_error_code(), array( 'baidu_over_quota','baidu_over_quota_cached' ), true ) ? 'quota' : 'partial';
                                $stats['baidu']['submitted'] = $submitted_total;
                                $stats['baidu']['message'] = '已提交 ' . $submitted_total . ' 条；后续批次：' . $second->get_error_message();
                            } else {
                                $rest_success = (int) ( $second['success'] ?? 0 );
                                $submitted_total += $rest_success;
                                if ( $rest_success === count( $rest ) ) { self::mark_submitted( $rest, Baidu_Module::LAST_SIG ); }
                                $remain = max( 0, (int) ( $second['remain'] ?? $remain ) );
                                $stats['baidu']['status'] = 'success';
                                $stats['baidu']['submitted'] = $submitted_total;
                                $stats['baidu']['message'] = '每日增量提交完成；剩余配额 ' . $remain . '。';
                            }
                        } else {
                            $stats['baidu']['status'] = 'success';
                            $stats['baidu']['submitted'] = $submitted_total;
                            $stats['baidu']['message'] = '每日增量提交完成；剩余配额 ' . $remain . '。';
                        }
                    }
                } else {
                    $stats['baidu']['status'] = 'idle';
                    $stats['baidu']['message'] = '没有尚未成功提交的新建或更新 URL。';
                }
            }
        } else {
            $stats['baidu']['message'] = ! Settings::module_enabled( 'baidu' ) ? '百度提交模块已关闭。' : '尚未配置百度已验证站点 / API Token。';
        }

        return self::finish( $stats );
    }

    /** @return array<int,array{id:int,url:string,sig:string}> */
    private static function pending_posts( string $channel, int $limit ): array {
        $meta_key = 'baidu' === $channel ? Baidu_Module::LAST_SIG : IndexNow_Module::LAST_SIG;
        $limit = min( 500, max( 1, $limit ) );
        $batch = 250;
        $offset = 0;
        $scanned = 0;
        $scan_cap = min( 10000, max( 2000, $limit * 10 ) );
        $out = array();
        $indexable = new Sitemap_Content();

        while ( count( $out ) < $limit && $scanned < $scan_cap ) {
            $posts = get_posts( array(
                'post_type'      => Utils::public_post_types(),
                'post_status'    => 'publish',
                'posts_per_page' => $batch,
                'offset'         => $offset,
                'orderby'        => 'modified',
                'order'          => 'DESC',
                'no_found_rows'  => true,
            ) );
            if ( ! $posts ) { break; }
            foreach ( $posts as $post ) {
                ++$scanned;
                $url = $indexable->indexable_post_url( $post );
                if ( '' === $url ) { continue; }
                $sig = self::post_signature( $post );
                if ( '' === $sig || $sig === (string) get_post_meta( $post->ID, $meta_key, true ) ) { continue; }
                $out[] = array( 'id'=>(int) $post->ID, 'url'=>(string) $url, 'sig'=>$sig );
                if ( count( $out ) >= $limit ) { break; }
            }
            if ( count( $posts ) < $batch ) { break; }
            $offset += $batch;
        }
        return $out;
    }

    /** @param array<int,array{id:int,url:string,sig:string}> $rows */
    private static function mark_submitted( array $rows, string $meta_key ): void {
        foreach ( $rows as $row ) {
            if ( ! empty( $row['id'] ) && ! empty( $row['sig'] ) ) {
                update_post_meta( (int) $row['id'], $meta_key, (string) $row['sig'] );
            }
        }
    }

    public static function post_signature( \WP_Post $post ): string {
        $base = (string) $post->post_modified_gmt;
        if ( '' === $base ) { return ''; }
        $dirty = trim( (string) get_post_meta( $post->ID, self::DIRTY_META, true ) );
        // Backward compatible: pages never explicitly dirtied keep the historical
        // post_modified_gmt signature, so upgrading does not re-submit the whole site.
        return '' === $dirty ? $base : $base . '|' . $dirty;
    }

    public static function mark_dirty( array $post_ids ): void {
        foreach ( array_values( array_unique( array_map( 'absint', $post_ids ) ) ) as $post_id ) {
            if ( $post_id <= 0 ) { continue; }
            update_post_meta( $post_id, self::DIRTY_META, wp_generate_uuid4() );
        }
    }

    public static function mark_posts_success( array $post_ids, string $meta_key ): void {
        foreach ( array_values( array_unique( array_map( 'absint', $post_ids ) ) ) as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post instanceof \WP_Post ) { continue; }
            $sig = self::post_signature( $post );
            if ( '' !== $sig ) { update_post_meta( $post_id, $meta_key, $sig ); }
        }
    }

    private static function finish( array $stats ): array {
        update_option( self::LAST_RUN_OPTION, current_time( 'mysql' ), false );
        update_option( self::LAST_STATS_OPTION, $stats, false );
        return $stats;
    }

    public static function last_run(): string {
        return (string) get_option( self::LAST_RUN_OPTION, '' );
    }

    public static function last_stats(): array {
        $stats = get_option( self::LAST_STATS_OPTION, array() );
        return is_array( $stats ) ? $stats : array();
    }

    public static function next_run(): int {
        return (int) ( wp_next_scheduled( self::CRON ) ?: 0 );
    }
}
