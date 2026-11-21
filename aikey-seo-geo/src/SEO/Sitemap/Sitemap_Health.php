<?php
namespace AIKEY\SeoGeo\SEO\Sitemap;

use AIKEY\SeoGeo\Core\Conflict_Detector;
use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\SEO\Meta\Meta_Repository;

defined( 'ABSPATH' ) || exit;

/** Low-frequency public Sitemap/robots health diagnostics. */
final class Sitemap_Health {
    public const CRON = 'aikey_seo_geo_daily_sitemap_health';

    private const LAST_RUN_OPTION = 'aikey_seo_geo_sitemap_health_last_run';
    private const LAST_RESULT_OPTION = 'aikey_seo_geo_sitemap_health_last_result';
    private const LOCK_TRANSIENT = 'aikey_seo_geo_sitemap_health_lock';

    public static function register_hooks(): void {
        add_action( 'init', array( self::class, 'ensure_schedule' ), 31 );
        add_action( self::CRON, array( self::class, 'cron_run' ) );
    }

    public static function ensure_schedule(): void {
        if ( ! Sitemap_Module::is_active() ) {
            wp_clear_scheduled_hook( self::CRON );
            return;
        }
        if ( ! wp_next_scheduled( self::CRON ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
        }
    }

    public static function cron_run(): void { self::run( false ); }

    /** @return array<string,mixed> */
    public static function run( bool $manual = false ): array {
        $result = array(
            'manual'     => $manual,
            'checked_at' => current_time( 'mysql' ),
            'sitemap'    => self::empty_status(),
            'robots'     => self::empty_status(),
        );

        if ( ! Settings::module_enabled( 'sitemap' ) ) {
            $result['sitemap']['message'] = 'XML Sitemap 模块已关闭。';
            $result['robots']['message'] = 'XML Sitemap 模块已关闭，未检查 robots.txt。';
            return self::finish( $result );
        }
        if ( ! (bool) get_option( 'blog_public', true ) ) {
            $result['sitemap']['message'] = 'WordPress 当前设置为不建议搜索引擎索引本站。';
            $result['robots']['message'] = '站点当前不可公开索引，已跳过自动检查。';
            return self::finish( $result );
        }
        if ( ! Conflict_Detector::sitemap_allowed() ) {
            $summary = Conflict_Detector::sitemap_summary();
            $message = '检测到其他 Sitemap/SEO 插件' . ( '' !== $summary ? '：' . $summary : '' ) . '。Aikeyboost未强制接管，因此不会重复输出 Sitemap。';
            $result['sitemap']['message'] = $message;
            $result['robots']['message'] = $message;
            return self::finish( $result );
        }
        if ( get_transient( self::LOCK_TRANSIENT ) ) {
            $result['sitemap']['message'] = '已有 Sitemap 健康检查正在运行，请稍后重试。';
            $result['robots']['message'] = '已有 Sitemap 健康检查正在运行，请稍后重试。';
            return $result;
        }

        set_transient( self::LOCK_TRANSIENT, '1', 2 * MINUTE_IN_SECONDS );
        try {
            $result['sitemap'] = self::check_sitemap( $manual );
            $result['robots'] = self::check_robots();
        } catch ( \Throwable $e ) {
            $safe = '检查过程中发生运行时异常，已安全中止。';
            if ( 'success' !== (string) ( $result['sitemap']['status'] ?? '' ) ) {
                $result['sitemap'] = array( 'status'=>'error', 'http'=>0, 'message'=>$safe );
            }
            if ( 'success' !== (string) ( $result['robots']['status'] ?? '' ) ) {
                $result['robots'] = array( 'status'=>'error', 'http'=>0, 'message'=>$safe );
            }
        } finally {
            delete_transient( self::LOCK_TRANSIENT );
        }
        return self::finish( $result );
    }

    /** @return array<string,mixed> */
    private static function check_sitemap( bool $manual ): array {
        $response = self::fetch( Sitemap_Module::main_url(), 6 * 1024 * 1024 );
        if ( is_wp_error( $response ) ) {
            return array( 'status'=>'error', 'http'=>0, 'message'=>self::safe_error( $response->get_error_message() ) );
        }
        $http = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        $content_type = function_exists( 'wp_remote_retrieve_header' ) ? (string) wp_remote_retrieve_header( $response, 'content-type' ) : '';
        $analysis = self::inspect_sitemap_document( $http, $body, $content_type, self::physical_sitemap_exists() );
        if ( 'success' !== (string) ( $analysis['status'] ?? '' ) ) {
            return self::public_analysis( $analysis );
        }

        $stats = Sitemap_Cache::stats();
        $total = max( 0, (int) ( $stats['total'] ?? 0 ) );
        if ( $total <= 0 ) {
            return array( 'status'=>'error', 'http'=>$http, 'message'=>'公网 Sitemap XML 非空，但插件运行统计为 0 URL，可能存在缓存/路由冲突。' );
        }
        if ( (int) ( $stats['home'] ?? 0 ) < 1 ) {
            return array( 'status'=>'error', 'http'=>$http, 'message'=>'Sitemap 未包含网站首页。请检查首页 index/canonical/重定向状态。' );
        }

        $page_probe = new Sitemap_Content();
        if ( $page_probe->indexable_page_exists() && (int) ( $stats['page'] ?? 0 ) < 1 ) {
            return array( 'status'=>'error', 'http'=>$http, 'message'=>'检测到至少一个符合索引条件的 WordPress Page，但 Sitemap 统计没有 Page，属于漏收录异常。' );
        }

        $root = (string) ( $analysis['root'] ?? '' );
        $public_count = (int) ( $analysis['count'] ?? 0 );
        if ( 'urlset' === $root && $public_count !== $total ) {
            return array( 'status'=>'error', 'http'=>$http, 'message'=>'公网 Sitemap URL 数量与插件生成统计不一致，可能存在 CDN/页面缓存或路由覆盖。' );
        }

        $probe_note = '';
        if ( $manual ) {
            $probe_urls = is_array( $analysis['urls'] ?? null ) ? $analysis['urls'] : array();
            if ( 'sitemapindex' === $root && $probe_urls ) {
                $child = self::fetch( (string) $probe_urls[0], 2 * 1024 * 1024 );
                if ( ! is_wp_error( $child ) ) {
                    $child_type = function_exists( 'wp_remote_retrieve_header' ) ? (string) wp_remote_retrieve_header( $child, 'content-type' ) : '';
                    $child_analysis = self::inspect_sitemap_document(
                        (int) wp_remote_retrieve_response_code( $child ),
                        (string) wp_remote_retrieve_body( $child ),
                        $child_type,
                        false
                    );
                    if ( 'success' !== (string) ( $child_analysis['status'] ?? '' ) || 'urlset' !== (string) ( $child_analysis['root'] ?? '' ) ) {
                        return array( 'status'=>'error', 'http'=>$http, 'message'=>'主 Sitemap Index 正常，但首个子 Sitemap 无法通过 XML/URL 校验。' );
                    }
                    $probe_urls = is_array( $child_analysis['urls'] ?? null ) ? $child_analysis['urls'] : array();
                }
            }
            $probe = self::probe_public_urls( $probe_urls, $total <= 25 ? 25 : 10 );
            if ( ! empty( $probe['bad'] ) ) {
                return array( 'status'=>'error', 'http'=>$http, 'message'=>'Sitemap URL 公网抽查发现 404/4xx/5xx：' . (string) $probe['bad'][0] );
            }
            if ( ! empty( $probe['redirects'] ) ) {
                return array( 'status'=>'error', 'http'=>$http, 'message'=>'Sitemap URL 公网抽查发现 301/302/3xx：' . (string) $probe['redirects'][0] . '。跳转 URL 不应进入 Sitemap。' );
            }
            if ( (int) ( $probe['checked'] ?? 0 ) > 0 ) {
                $probe_note = '；公网抽查 ' . (int) $probe['checked'] . ' 个 URL 未发现 3xx/404';
            } elseif ( (int) ( $probe['unavailable'] ?? 0 ) > 0 ) {
                $probe_note = '；URL 公网抽查受服务器回环请求限制，未作为失败处理';
            }
        }

        $mode = 'urlset' === $root ? '单文件 URLSet' : 'Sitemap Index';
        $message = '主 Sitemap 正常：' . $mode . '；有效 URL ' . $total
            . '；首页 ' . (int) ( $stats['home'] ?? 0 )
            . '；Page ' . (int) ( $stats['page'] ?? 0 )
            . '；Post ' . (int) ( $stats['post'] ?? 0 )
            . '；CPT ' . (int) ( $stats['cpt'] ?? 0 )
            . '；Taxonomy ' . (int) ( $stats['taxonomy'] ?? 0 ) . $probe_note . '。';
        return array( 'status'=>'success', 'http'=>$http, 'message'=>$message, 'count'=>$total, 'mode'=>$root );
    }

    /** @return array{status:string,http:int,message:string} */
    private static function check_robots(): array {
        if ( Robots_File_Manager::is_symlink() ) {
            return array( 'status'=>'error', 'http'=>0, 'message'=>'网站根目录 robots.txt 是符号链接。为避免写入未知目标，插件不会管理该文件。' );
        }

        $physical = Robots_File_Manager::exists();
        $expected = '';
        if ( $physical ) {
            $read = Robots_File_Manager::read();
            if ( is_wp_error( $read ) ) {
                return array( 'status'=>'error', 'http'=>0, 'message'=>self::safe_error( $read->get_error_message() ) );
            }
            $expected = (string) $read;
        }

        $response = self::fetch( home_url( '/robots.txt' ), 256 * 1024 );
        if ( is_wp_error( $response ) ) {
            return array( 'status'=>'error', 'http'=>0, 'message'=>self::safe_error( $response->get_error_message() ) );
        }
        return self::validate_robots_payload(
            (int) wp_remote_retrieve_response_code( $response ),
            (string) wp_remote_retrieve_body( $response ),
            Sitemap_Module::main_url(),
            '',
            $expected,
            $physical
        );
    }

    public static function physical_robots_exists(): bool { return Robots_File_Manager::exists(); }

    /** Physical sitemap.xml/sitemap_index.xml can shadow the plugin's dynamic routes. */
    public static function physical_sitemap_exists(): bool {
        $roots = array( rtrim( (string) ABSPATH, '/\\' ), dirname( Robots_File_Manager::path() ) );
        foreach ( array_unique( $roots ) as $root ) {
            foreach ( array( 'sitemap.xml', 'sitemap_index.xml' ) as $name ) {
                if ( is_file( rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR . $name ) ) { return true; }
            }
        }
        return false;
    }

    private static function fetch( string $url, int $limit ): array|\WP_Error {
        return wp_safe_remote_get(
            $url,
            array(
                'timeout'             => 8,
                'redirection'         => 2,
                'limit_response_size' => max( 1024, $limit ),
                'user-agent'          => 'AIQIBANG-SEO-GEO-SitemapHealth/' . AIKEY_SEO_GEO_VERSION,
                'headers'             => array(
                    'Accept'        => 'application/xml,text/xml,text/plain;q=0.9,*/*;q=0.5',
                    'Cache-Control' => 'no-cache',
                    'Pragma'        => 'no-cache',
                ),
            )
        );
    }

    /**
     * Legacy/public validator kept for tests and compatibility.
     *
     * @param string[] $unused_legacy_manual_urls
     * @return array{status:string,http:int,message:string}
     */
    public static function validate_sitemap_payload( int $http_code, string $body, array $unused_legacy_manual_urls = array(), bool $physical_file = false, string $content_type = '' ): array {
        return self::public_analysis( self::inspect_sitemap_document( $http_code, $body, $content_type, $physical_file ) );
    }

    /** @return array<string,mixed> */
    public static function inspect_sitemap_document( int $http_code, string $body, string $content_type = '', bool $physical_file = false ): array {
        if ( 200 !== $http_code ) {
            return array( 'status'=>'error', 'http'=>$http_code, 'message'=>'Sitemap HTTP 状态不是 200。' );
        }
        if ( '' !== $content_type && false === stripos( $content_type, 'xml' ) ) {
            return array( 'status'=>'error', 'http'=>$http_code, 'message'=>'Sitemap Content-Type 不是 XML。' );
        }
        if ( '' === trim( $body ) ) {
            return array( 'status'=>'error', 'http'=>$http_code, 'message'=>'Sitemap 响应为空。' );
        }
        $body = preg_replace( '/^\xEF\xBB\xBF/', '', $body );
        $body = is_string( $body ) ? $body : '';
        $trimmed = ltrim( $body );
        if ( ! str_starts_with( $trimmed, '<?xml' ) ) {
            return array( 'status'=>'error', 'http'=>$http_code, 'message'=>'Sitemap 缺少合法 XML Header，或 XML 前混入了 Warning/Notice/HTML/debug 输出。' );
        }
        if ( preg_match( '/<!DOCTYPE|<!ENTITY|<html\b|<body\b|\b(?:PHP )?(?:Warning|Notice|Deprecated|Fatal error)\s*:/i', $body ) ) {
            return array( 'status'=>'error', 'http'=>$http_code, 'message'=>'Sitemap XML 混入 HTML、PHP 错误输出或不允许的 XML 声明。' );
        }
        if ( ! self::xml_is_well_formed( $body ) ) {
            return array( 'status'=>'error', 'http'=>$http_code, 'message'=>'Sitemap XML 无法通过解析校验。' );
        }
        if ( ! preg_match( '/<(urlset|sitemapindex)\b[^>]*xmlns=["\']http:\/\/www\.sitemaps\.org\/schemas\/sitemap\/0\.9["\'][^>]*>/i', $body, $root_match ) ) {
            return array( 'status'=>'error', 'http'=>$http_code, 'message'=>'Sitemap 根节点或标准 namespace 不正确。' );
        }
        $root = strtolower( (string) $root_match[1] );
        preg_match_all( '/<loc>\s*(.*?)\s*<\/loc>/is', $body, $matches );
        $raw_urls = is_array( $matches[1] ?? null ) ? $matches[1] : array();
        $urls = array();
        $seen = array();
        $home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        foreach ( $raw_urls as $raw ) {
            $url = html_entity_decode( trim( wp_strip_all_tags( (string) $raw ) ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
            $url = esc_url_raw( $url, array( 'http','https' ) );
            if ( '' === $url ) {
                return array( 'status'=>'error', 'http'=>$http_code, 'message'=>'Sitemap 包含非法 loc URL。' );
            }
            if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $home_host ) {
                return array( 'status'=>'error', 'http'=>$http_code, 'message'=>'Sitemap 包含非本站 URL：' . self::safe_error( $url ) );
            }
            $key = self::normalize_url( $url );
            if ( isset( $seen[ $key ] ) ) {
                return array( 'status'=>'error', 'http'=>$http_code, 'message'=>'Sitemap 包含重复 URL：' . self::safe_error( $url ) );
            }
            $seen[ $key ] = true;
            $urls[] = $url;
        }
        if ( ! $urls ) {
            return array( 'status'=>'error', 'http'=>$http_code, 'message'=>'Sitemap XML 结构存在，但没有任何有效 URL/子 Sitemap，属于空 Sitemap。' );
        }
        $item_tag = 'urlset' === $root ? 'url' : 'sitemap';
        preg_match_all( '/<' . $item_tag . '(?:\s|>)/i', $body, $item_matches );
        $item_count = count( $item_matches[0] ?? array() );
        if ( $item_count !== count( $urls ) ) {
            return array( 'status'=>'error', 'http'=>$http_code, 'message'=>'Sitemap 条目数量与 loc 数量不一致，XML 结构异常。' );
        }
        if ( $physical_file ) {
            return array( 'status'=>'error', 'http'=>$http_code, 'message'=>'检测到服务器物理 sitemap.xml 或 sitemap_index.xml，它可能覆盖插件动态 Sitemap。请移除或改名冲突文件。' );
        }
        if ( 'urlset' === $root && ! isset( $seen[ self::normalize_url( home_url( '/' ) ) ] ) ) {
            return array( 'status'=>'error', 'http'=>$http_code, 'message'=>'小型站点 Sitemap 未包含网站首页。' );
        }
        return array(
            'status'  => 'success',
            'http'    => $http_code,
            'message' => '主 Sitemap 可公开访问，XML 合法、非空、无重复/外域 URL。',
            'root'    => $root,
            'count'   => count( $urls ),
            'urls'    => $urls,
        );
    }

    /** @return array{status:string,http:int,message:string} */
    public static function validate_robots_payload(
        int $http_code,
        string $body,
        string $sitemap_url,
        string $baidu_sitemap_url = '',
        string $physical_expected = '',
        bool $physical_file = false
    ): array {
        if ( 200 !== $http_code ) {
            return array( 'status'=>'error', 'http'=>$http_code, 'message'=>'robots.txt HTTP 状态不是 200。' );
        }

        $body = self::normalize_robots_text( $body );
        $physical_expected = self::normalize_robots_text( $physical_expected );
        if ( self::wildcard_disallows_all( $body ) ) {
            return array( 'status'=>'error', 'http'=>$http_code, 'message'=>'robots.txt 对 User-agent: * 设置了 Disallow: /，会阻止公开 crawler 抓取全站。' );
        }

        if ( $physical_file && '' !== trim( $physical_expected ) && trim( $body ) !== trim( $physical_expected ) ) {
            return array( 'status'=>'error', 'http'=>$http_code, 'message'=>'物理 robots.txt 已更新，但公网返回内容与文件不一致。请清理 CDN/Nginx/Apache 缓存，并确认 Web 服务器文档根目录。' );
        }

        $sitemap_lines = self::sitemap_lines( $body );
        $main_key = self::normalize_url( $sitemap_url );
        $main_count = 0;
        $extra = 0;
        foreach ( $sitemap_lines as $url ) {
            $key = self::normalize_url( $url );
            if ( $key === $main_key ) { $main_count++; continue; }
            $path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
            if ( in_array( '/' . ltrim( $path, '/' ), array( '/sitemap.xml','/sitemap_index.xml','/baidu-sitemap.xml','/wp-sitemap.xml' ), true ) ) {
                return array( 'status'=>'error', 'http'=>$http_code, 'message'=>'robots.txt 仍包含旧域名、旧版或 WordPress 原生 Sitemap 声明，请在插件后台重新保存物理 robots.txt 自动清理。' );
            }
            $extra++;
        }
        if ( 1 !== $main_count ) {
            return array( 'status'=>'warning', 'http'=>$http_code, 'message'=>0 === $main_count ? 'robots.txt 尚未声明推荐主 Sitemap /sitemap.xml；保存物理文件时会自动补全。' : 'robots.txt 重复声明了主 Sitemap，请重新保存物理 robots.txt 自动去重。' );
        }
        if ( $extra > 0 ) {
            return array( 'status'=>'warning', 'http'=>$http_code, 'message'=>'robots.txt 已声明 Aikeyboost主 Sitemap，但还存在 ' . $extra . ' 条其他 Sitemap 声明；插件不会擅自删除未知第三方规则，请人工确认是否需要保留。' );
        }
        if ( $physical_file ) {
            return array( 'status'=>'success', 'http'=>$http_code, 'message'=>'物理 robots.txt 与公网响应一致，仅声明一个推荐主 Sitemap /sitemap.xml。' );
        }
        return array( 'status'=>'warning', 'http'=>$http_code, 'message'=>'当前 robots.txt 可访问并声明主 Sitemap，但网站根目录尚未创建由插件管理的物理 robots.txt；可在“设置”页面保存后自动创建。' );
    }

    /** @return array<string,mixed> */
    private static function probe_public_urls( array $urls, int $limit ): array {
        $checked = 0;
        $unavailable = 0;
        $bad = array();
        $redirects = array();
        $home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        foreach ( array_slice( array_values( array_unique( $urls ) ), 0, max( 0, $limit ) ) as $url ) {
            if ( strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) ) !== $home_host ) { continue; }
            $response = wp_safe_remote_get( (string) $url, array(
                'timeout' => 4,
                'redirection' => 0,
                'limit_response_size' => 1,
                'user-agent' => 'AIQIBANG-SEO-GEO-SitemapURLCheck/' . AIKEY_SEO_GEO_VERSION,
            ) );
            if ( is_wp_error( $response ) ) { $unavailable++; continue; }
            $checked++;
            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( $code >= 300 && $code < 400 ) { $redirects[] = (string) $url . ' (' . $code . ')'; }
            elseif ( $code < 200 || $code >= 400 ) { $bad[] = (string) $url . ' (' . $code . ')'; }
        }
        return array( 'checked'=>$checked, 'unavailable'=>$unavailable, 'bad'=>$bad, 'redirects'=>$redirects );
    }


    private static function xml_is_well_formed( string $xml ): bool {
        if ( function_exists( 'simplexml_load_string' ) ) {
            $previous = libxml_use_internal_errors( true );
            libxml_clear_errors();
            $parsed = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET );
            $ok = false !== $parsed && empty( libxml_get_errors() );
            libxml_clear_errors();
            libxml_use_internal_errors( $previous );
            return $ok;
        }
        // Extension-free fallback: generated Sitemap is deliberately simple. This
        // checks balanced standard item/root tags; release tests additionally parse
        // the final XML with a real XML parser.
        $probe = strtolower( $xml );
        $root_ok = ( 1 === substr_count( $probe, '<urlset ' ) && 1 === substr_count( $probe, '</urlset>' ) )
            || ( 1 === substr_count( $probe, '<sitemapindex ' ) && 1 === substr_count( $probe, '</sitemapindex>' ) );
        if ( ! $root_ok ) { return false; }
        return substr_count( $probe, '<url>' ) === substr_count( $probe, '</url>' )
            && substr_count( $probe, '<sitemap>' ) === substr_count( $probe, '</sitemap>' )
            && substr_count( $probe, '<loc>' ) === substr_count( $probe, '</loc>' );
    }

    /** @return string[] */
    private static function sitemap_lines( string $body ): array {
        preg_match_all( '/^[\t ]*Sitemap[\t ]*:[\t ]*(\S+)[\t ]*$/mi', $body, $matches );
        $urls = array();
        foreach ( $matches[1] ?? array() as $raw ) {
            $url = esc_url_raw( trim( (string) $raw ), array( 'http','https' ) );
            if ( '' !== $url ) { $urls[] = $url; }
        }
        return $urls;
    }

    private static function normalize_url( string $url ): string {
        $url = esc_url_raw( trim( $url ), array( 'http','https' ) );
        if ( '' === $url ) { return ''; }
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $port = (string) wp_parse_url( $url, PHP_URL_PORT );
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $query = (string) wp_parse_url( $url, PHP_URL_QUERY );
        $path = '/' === $path || '' === $path ? '/' : untrailingslashit( '/' . ltrim( rawurldecode( $path ), '/' ) );
        return $scheme . '://' . $host . ( '' !== $port ? ':' . $port : '' ) . $path . ( '' !== $query ? '?' . $query : '' );
    }

    /** @param array<string,mixed> $analysis @return array{status:string,http:int,message:string} */
    private static function public_analysis( array $analysis ): array {
        return array(
            'status' => (string) ( $analysis['status'] ?? 'error' ),
            'http' => (int) ( $analysis['http'] ?? 0 ),
            'message' => (string) ( $analysis['message'] ?? 'Sitemap 检查失败。' ),
        );
    }

    private static function normalize_robots_text( string $text ): string {
        $text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
        $text = is_string( $text ) ? $text : '';
        return str_replace( array( "\r\n", "\r" ), "\n", trim( $text ) );
    }

    private static function wildcard_disallows_all( string $body ): bool {
        $agents = array();
        $has_rules = false;
        foreach ( preg_split( '/\n+/', $body ) ?: array() as $raw_line ) {
            $line = trim( preg_replace( '/#.*$/', '', (string) $raw_line ) ?? '' );
            if ( '' === $line ) { continue; }
            if ( preg_match( '/^User-agent\s*:\s*(.+)$/i', $line, $match ) ) {
                if ( $has_rules ) { $agents = array(); $has_rules = false; }
                $agents[] = strtolower( trim( (string) $match[1] ) );
                continue;
            }
            if ( preg_match( '/^(Allow|Disallow)\s*:\s*(.*)$/i', $line, $match ) ) {
                $has_rules = true;
                if ( 'disallow' === strtolower( (string) $match[1] ) && '/' === trim( (string) $match[2] ) && in_array( '*', $agents, true ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array{status:string,http:int,message:string} */
    private static function empty_status(): array { return array( 'status'=>'skipped', 'http'=>0, 'message'=>'' ); }

    private static function safe_error( string $message ): string {
        $message = sanitize_text_field( $message );
        if ( function_exists( 'mb_substr' ) ) { $message = mb_substr( $message, 0, 180, 'UTF-8' ); }
        else { $message = substr( $message, 0, 180 ); }
        return '' !== $message ? $message : '网络检查失败。';
    }

    /** @param array<string,mixed> $result */
    private static function finish( array $result ): array {
        update_option( self::LAST_RUN_OPTION, (string) ( $result['checked_at'] ?? current_time( 'mysql' ) ), false );
        update_option( self::LAST_RESULT_OPTION, $result, false );
        return $result;
    }

    public static function last_run(): string { return (string) get_option( self::LAST_RUN_OPTION, '' ); }
    /** @return array<string,mixed> */
    public static function last_result(): array {
        $result = get_option( self::LAST_RESULT_OPTION, array() );
        return is_array( $result ) ? $result : array();
    }
    public static function next_run(): int { return (int) ( wp_next_scheduled( self::CRON ) ?: 0 ); }
}
