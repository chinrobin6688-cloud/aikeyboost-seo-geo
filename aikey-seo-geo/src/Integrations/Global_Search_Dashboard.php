<?php
namespace AIKEY\SeoGeo\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only view of already fetched Search Console and Bing Webmaster data.
 *
 * Never sends requests or persists derived stats. The two platforms use
 * different coverage and timezones; their figures are not additive.
 */
final class Global_Search_Dashboard {
    private const BING_CACHE = 'aikey_seo_geo_bing_search_stats';
    private const GOOGLE_CACHE = 'aikey_gsc_search_analytics';
    private const MAX_AGE = 15 * MINUTE_IN_SECONDS;

    /** @return array<string,mixed> */
    public static function snapshot( int $limit = 7 ): array {
        $limit = 30 === $limit ? 30 : 7;
        return array(
            'limit' => $limit,
            'google' => self::source(
                'google', Google_Search_Console::connected(),
                Google_Search_Console::property(), self::GOOGLE_CACHE, $limit
            ),
            'bing' => self::source(
                'bing', '' !== Webmaster_Data::credential(),
                Webmaster_Data::configured_site(), self::BING_CACHE, $limit
            ),
        );
    }

    /** Read and validate only the expected provider's existing transient. */
    private static function source( string $provider, bool $connected, string $site, string $key, int $limit ): array {
        $data = array(
            'status' => $connected ? 'not_fetched' : 'not_connected',
            'site' => $site,
            'fetched_at' => 0,
            'rows' => array(),
            'date_start' => '',
            'date_end' => '',
            'clicks' => 0,
            'impressions' => 0,
            'ctr' => null,
            'position' => null,
        );
        if ( ! $connected || '' === $site ) { return $data; }
        $cached = get_transient( $key );
        if ( ! is_array( $cached ) || ! isset( $cached['site'], $cached['fetched_at'], $cached['rows'] )
            || ! is_string( $cached['site'] ) || $site !== $cached['site']
            || ! is_int( $cached['fetched_at'] ) || ! is_array( $cached['rows'] )
            || $cached['fetched_at'] <= 0 || $cached['fetched_at'] > time() + 30
            || time() - $cached['fetched_at'] > self::MAX_AGE ) {
            return $data;
        }
        $data['fetched_at'] = $cached['fetched_at'];
        $parsed = self::valid_rows( $provider, $cached['rows'] );
        if ( null === $parsed ) {
            $data['status'] = 'invalid_cache';
            return $data;
        }
        if ( ! $parsed ) {
            $data['status'] = 'empty';
            return $data;
        }

        // The source caches contain a maximum of 30 records each; sort by the
        // provider-reported calendar date and never synthesize missing days.
        usort( $parsed, static fn( array $a, array $b ): int => strcmp( $b['date'], $a['date'] ) );
        $parsed = array_slice( $parsed, 0, $limit );
        $data['status'] = 'ready';
        $data['rows'] = $parsed;
        $data['date_end'] = $parsed[0]['date'];
        $data['date_start'] = $parsed[ count( $parsed ) - 1 ]['date'];

        $position_weighted = 0.0;
        foreach ( $parsed as $row ) {
            $data['clicks'] += $row['clicks'];
            $data['impressions'] += $row['impressions'];
            if ( 'google' === $provider ) { $position_weighted += $row['position'] * $row['impressions']; }
        }
        if ( $data['impressions'] > 0 ) {
            $data['ctr'] = $data['clicks'] / $data['impressions'];
            if ( 'google' === $provider ) {
                $data['position'] = $position_weighted / $data['impressions'];
            }
        }
        return $data;
    }

    /**
     * Strictly validate cached input: transients are untrusted across plugin
     * integrations. Any invalid row invalidates that provider, not the other.
     */
    public static function valid_rows( string $provider, array $input ): ?array {
        if ( ! in_array( $provider, array( 'google', 'bing' ), true )
            || array_values( $input ) !== $input || count( $input ) > 30 ) { return null; }
        $seen = array();
        $output = array();
        foreach ( $input as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['date'], $row['clicks'], $row['impressions'] )
                || ! is_string( $row['date'] ) || ! is_int( $row['clicks'] ) || ! is_int( $row['impressions'] )
                || $row['clicks'] < 0 || $row['impressions'] < 0
                || $row['clicks'] > 1000000000000 || $row['impressions'] > 1000000000000
                || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $row['date'] ) ) { return null; }
            $date = $row['date'];
            $parts = explode( '-', $date );
            if ( ! checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] )
                || isset( $seen[ $date ] ) ) { return null; }
            $seen[ $date ] = true;
            $record = array( 'date'=>$date, 'clicks'=>$row['clicks'], 'impressions'=>$row['impressions'] );
            if ( 'google' === $provider ) {
                if ( ! isset( $row['ctr'], $row['position'] )
                    || ! is_numeric( $row['ctr'] ) || ! is_numeric( $row['position'] )
                    || ! is_finite( (float) $row['ctr'] ) || ! is_finite( (float) $row['position'] )
                    || (float) $row['ctr'] < 0 || (float) $row['ctr'] > 1
                    || (float) $row['position'] < 0 || (float) $row['position'] > 1000000 ) {
                    return null;
                }
                $record['ctr'] = (float) $row['ctr'];
                $record['position'] = (float) $row['position'];
            }
            $output[] = $record;
        }
        return $output;
    }

    private static function number( int $number ): string {
        return number_format_i18n( $number, 0 );
    }

    private static function percentage( ?float $fraction ): string {
        return null === $fraction ? '—' : number_format_i18n( $fraction * 100, 2 ) . '%';
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $selected = isset( $_GET['aikey_window'] ) && is_string( $_GET['aikey_window'] )
            && '30' === $_GET['aikey_window'] ? 30 : 7;
        $data = self::snapshot( $selected );
        echo '<section class="aikey-global-search-dashboard" aria-label="海外搜索数据统一概览">';
        echo '<h2>全球搜索数据概览</h2>';
        echo '<p>同屏展示两个独立官方来源的已缓存数据。页面加载不会触发 Google/Bing API 请求；要更新数据，请使用各平台下方的手动读取按钮。</p>';
        echo '<p><strong>查看最近返回的日期记录：</strong> ';
        foreach ( array( 7, 30 ) as $window ) {
            $url = admin_url( 'admin.php?page=aikey-seo-geo-webmaster-data&aikey_window=' . $window );
            echo '<a class="button ' . ( $selected === $window ? 'button-primary' : 'button-secondary' )
                . '" href="' . esc_url( $url ) . '">' . (int) $window . ' 条以内</a> ';
        }
        echo '</p><p class="description">只统计各平台实际返回的有数据日期，不补零、不推算缺失日期；两个来源的时区、统计范围和处理延迟不同，不能直接合并或当作完全一致的市场份额。</p>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin:18px 0;">';
        self::render_source( 'Google Search Console · Web 搜索', $data['google'], 'PT（太平洋时间）', true );
        self::render_source( 'Bing Webmaster · 多垂直流量', $data['bing'], 'UTC', false );
        echo '</div>';
        echo '<h3>AI 搜索引用数据</h3>';
        echo '<p><strong>当前未接入独立 AI 引用统计。</strong>Google 传统 Web Search 数据和 Bing 多垂直流量均不能当成 ChatGPT、Copilot、Perplexity 或 Google AI 平台的实际引用次数。爬虫诊断也不代表已经引用。</p>';
        echo '</section>';
    }

    private static function render_source( string $title, array $source, string $timezone, bool $google ): void {
        echo '<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px;">';
        echo '<h3 style="margin-top:0;">' . esc_html( $title ) . '</h3>';
        echo '<p><small>站点属性：<code>' . esc_html( $source['site'] ) . '</code></small></p>';
        switch ( $source['status'] ) {
            case 'not_connected':
                echo '<p>未连接：请在本页下方完成对应平台的授权或密钥配置。</p>';
                break;
            case 'not_fetched':
                echo '<p>暂无有效缓存：需要管理员主动读取，或此前缓存已过期；未发起自动请求。</p>';
                break;
            case 'invalid_cache':
                echo '<p>返回数据缓存结构无法验证；已隐藏统计，建议重新手动读取。</p>';
                break;
            case 'empty':
                echo '<p>官方接口成功返回空数据，不能据此认定网站没有被收录或处罚。</p>';
                break;
            case 'ready':
                echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;">';
                echo '<p><strong>点击</strong><br><span style="font-size:22px;">'
                    . esc_html( self::number( $source['clicks'] ) ) . '</span></p>';
                echo '<p><strong>曝光</strong><br><span style="font-size:22px;">'
                    . esc_html( self::number( $source['impressions'] ) ) . '</span></p>';
                echo '</div>';
                if ( $google ) {
                    echo '<p><strong>该记录集的加权 CTR：</strong>'
                        . esc_html( self::percentage( $source['ctr'] ) ) . '</p>';
                    echo '<p><strong>曝光加权平均排名：</strong>'
                        . esc_html( null === $source['position'] ? '—' : number_format_i18n( $source['position'], 1 ) )
                        . '</p>';
                } else {
                    echo '<p><strong>计算点击/曝光比：</strong>'
                        . esc_html( self::percentage( $source['ctr'] ) )
                        . '（仅按两项返回值计算，非额外官方 CTR 字段）</p>';
                }
                echo '<p><small>返回日期：' . esc_html( $source['date_start'] ) . ' 至 '
                    . esc_html( $source['date_end'] ) . '（' . esc_html( $timezone ) . '；实际 '
                    . count( $source['rows'] ) . ' 条）</small></p>';
                break;
        }
        if ( $source['fetched_at'] > 0 ) {
            echo '<p><small>获取时间：' . esc_html( wp_date( 'Y-m-d H:i', $source['fetched_at'] ) )
                . '（WordPress 网站时区）</small></p>';
        }
        echo '</div>';
    }
}
