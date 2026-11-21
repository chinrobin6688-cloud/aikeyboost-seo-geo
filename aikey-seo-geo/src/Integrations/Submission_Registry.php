<?php
namespace AIKEY\SeoGeo\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Public submission/discovery capabilities and official entry points.
 *
 * Only stable, publicly documented mechanisms are marked as directly automatable.
 * For platforms that expose only a webmaster console, Sitemap registration or crawler
 * guidance, Aikeyboost links to the official tool instead of imitating private endpoints.
 */
final class Submission_Registry {
    public static function search_engines(): array {
        return array(
            'baidu' => array(
                'label' => '百度搜索',
                'mode' => 'api',
                'status' => 'Aikeyboost可直接主动提交',
                'description' => '普通收录 API；可手动提交，也可在新发布/更新时自动推送。',
                'plugin_channel' => 'baidu',
                'sitemap' => 'standard',
                'links' => array(
                    array( 'label'=>'获取 API Token', 'url'=>'https://ziyuan.baidu.com/linksubmit/index', 'primary'=>true ),
                ),
            ),
            'bing' => array(
                'label' => '必应 Bing',
                'mode' => 'indexnow',
                'status' => 'Aikeyboost可直接主动提交',
                'description' => '通过 IndexNow 自动通知 Bing 及其他参与 IndexNow 的搜索引擎。',
                'plugin_channel' => 'indexnow',
                'sitemap' => 'standard',
                'links' => array(
                    array( 'label'=>'IndexNow 官方接入', 'url'=>'https://www.bing.com/indexnow/getstarted', 'primary'=>true ),
                    array( 'label'=>'Bing URL Submission', 'url'=>'https://www.bing.com/webmasters/help/url-submission-62f2860b' ),
                ),
            ),
            'sogou' => array(
                'label' => '搜狗搜索',
                'mode' => 'webmaster',
                'status' => '官方 URL 提交 / Sitemap',
                'description' => '搜狗资源平台提供已验证站点 URL 提交通道；Sitemap 能力仍可能受平台权限限制。当前没有公开稳定的服务端通用推送 API，因此不做私有接口自动化。',
                'sitemap' => 'standard',
                'links' => array(
                    array( 'label'=>'搜狗 URL 提交', 'url'=>'https://zhanzhang.sogou.com/index.php/sitelink/index', 'primary'=>true ),
                    array( 'label'=>'搜狗站长平台', 'url'=>'https://zhanzhang.sogou.com/' ),
                    array( 'label'=>'Sitemap 官方说明', 'url'=>'https://zhanzhang.sogou.com/index.php/help/sitemap' ),
                ),
            ),
            'so360' => array(
                'label' => '360搜索',
                'mode' => 'webmaster',
                'status' => 'Sitemap / 自动收录工具',
                'description' => '360提供站长平台 Sitemap 与自动收录工具；未使用未经公开确认的服务端私有接口。',
                'sitemap' => 'standard',
                'links' => array(
                    array( 'label'=>'360 网站提交', 'url'=>'https://info.so.com/site_submit.html', 'primary'=>true ),
                    array( 'label'=>'360 自动收录', 'url'=>'https://zhanzhang.so.com/sitetool/auto_include' ),
                    array( 'label'=>'360 Sitemap', 'url'=>'https://zhanzhang.so.com/?m=Sitemap' ),
                ),
            ),
            'shenma' => array(
                'label' => '神马搜索',
                'mode' => 'sitemap',
                'status' => '站长平台登记 Sitemap',
                'description' => '神马公开站长工具支持 Sitemap 与结构化开放数据，未公开通用 URL 推送 API。',
                'sitemap' => 'standard',
                'links' => array(
                    array( 'label'=>'神马站长平台', 'url'=>'https://zhanzhang.sm.cn/', 'primary'=>true ),
                    array( 'label'=>'Sitemap 官方说明', 'url'=>'https://zhanzhang.sm.cn/open/helpsitemap' ),
                ),
            ),
            'toutiao_search' => array(
                'label' => '头条/抖音搜索生态',
                'mode' => 'webmaster',
                'status' => '站长平台链接/Sitemap提交',
                'description' => '通过头条搜索站长平台提交 Sitemap/链接；不伪造未公开的通用服务端 API。',
                'sitemap' => 'standard',
                'links' => array(
                    array( 'label'=>'头条搜索站长平台', 'url'=>'https://zhanzhang.toutiao.com/', 'primary'=>true ),
                ),
            ),
            'chinaso' => array(
                'label' => '中国搜索 / 国搜',
                'mode' => 'sitemap',
                'status' => '站长平台登记 Sitemap',
                'description' => '中国搜索公开提供 Sitemap 提交与结构化数据工具。',
                'sitemap' => 'standard',
                'links' => array(
                    array( 'label'=>'国搜 Sitemap 提交', 'url'=>'https://www.chinaso.com/v1/sittj/index.html', 'primary'=>true ),
                ),
            ),
            'google' => array(
                'label' => 'Google',
                'mode' => 'sitemap',
                'status' => 'Search Console 登记 Sitemap',
                'description' => '普通网页没有通用实时 URL 推送 API；通过 Search Console/Sitemap 管理发现。',
                'sitemap' => 'standard',
                'links' => array(
                    array( 'label'=>'Google Search Console', 'url'=>'https://search.google.com/search-console', 'primary'=>true ),
                ),
            ),
        );
    }

    public static function ai_engines(): array {
        $rows = array(
            'baidu_ai' => array(
                'label'=>'百度文心 / 百度 AI 搜索', 'mode'=>'delegated', 'status'=>'复用百度搜索提交',
                'description'=>'没有独立 AI URL 推送接口；网页发现可复用百度普通收录 API 与百度抓取体系。',
                'via'=>'baidu',
            ),
            'nami_ai' => array(
                'label'=>'360 纳米 AI / 360 AI 搜索', 'mode'=>'delegated', 'status'=>'复用 360 搜索发现',
                'description'=>'没有公开独立 AI URL 推送 API；通过 360 搜索的站长工具、Sitemap 与可抓取性提高发现机会。',
                'via'=>'so360',
            ),
            'copilot' => array(
                'label'=>'Microsoft Copilot', 'mode'=>'delegated', 'status'=>'复用 Bing / IndexNow',
                'description'=>'网页发现与 Bing 搜索体系相关；Aikeyboost可通过 IndexNow 主动通知网页变更。',
                'via'=>'bing',
            ),
            'google_ai' => array(
                'label'=>'Gemini / Google AI', 'mode'=>'delegated', 'status'=>'复用 Google Search 发现',
                'description'=>'没有独立 Gemini URL 提交 API；Search Console/Sitemap 管理 Google 搜索发现。Google-Extended 是 robots.txt 产品令牌，不是独立 HTTP crawler。',
                'via'=>'google',
                'links'=>array( array( 'label'=>'Google crawler 官方说明', 'url'=>'https://developers.google.com/crawling/docs/crawlers-fetchers/google-common-crawlers' ) ),
            ),
            'doubao' => array(
                'label'=>'豆包', 'mode'=>'none', 'status'=>'暂无公开豆包独立 URL 提交 API',
                'description'=>'可维护字节搜索生态可抓取性；头条站长平台不是豆包专用 API。',
                'links'=>array( array( 'label'=>'头条搜索站长平台', 'url'=>'https://zhanzhang.toutiao.com/' ) ),
            ),
            'kimi' => array(
                'label'=>'Kimi', 'mode'=>'crawler', 'status'=>'无 URL 提交 API，提供官方搜索 crawler',
                'description'=>'Kimi-SearchBot 用于构建搜索索引；保持 crawler 可访问比伪造提交接口更可靠。',
                'links'=>array( array( 'label'=>'Kimi Crawlers', 'url'=>'https://www.kimi.ai/policies/kimi-crawlers', 'primary'=>true ) ),
            ),
            'openai' => array(
                'label'=>'ChatGPT', 'mode'=>'crawler', 'status'=>'暂无公开通用 URL 提交 API',
                'description'=>'公开网站可通过 OAI-SearchBot 被发现；重点是 robots/WAF 可访问与页面可索引。',
                'links'=>array( array( 'label'=>'OpenAI 发布商说明', 'url'=>'https://help.openai.com/en/articles/12627856-publishers-and-developers-faq', 'primary'=>true ) ),
            ),
            'claude' => array(
                'label'=>'Claude', 'mode'=>'crawler', 'status'=>'暂无公开通用 URL 提交 API',
                'description'=>'Claude-SearchBot 用于搜索发现，Claude-User 用于用户发起的网页访问；通过 robots 和可抓取性管理。',
                'links'=>array( array( 'label'=>'Claude Crawlers 官方说明', 'url'=>'https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler', 'primary'=>true ) ),
            ),
            'perplexity' => array(
                'label'=>'Perplexity', 'mode'=>'crawler', 'status'=>'暂无公开通用 URL 提交 API',
                'description'=>'PerplexityBot 用于搜索结果发现；官方提供 crawler 与 IP 说明。',
                'links'=>array( array( 'label'=>'Perplexity Crawlers', 'url'=>'https://docs.perplexity.ai/docs/resources/perplexity-crawlers', 'primary'=>true ) ),
            ),
        );

        // Keep Submission Center in sync with the complete engine registry. Platforms without
        // a public publisher URL submission mechanism still get a truthful discovery card,
        // instead of silently disappearing from the UI or being assigned a made-up API.
        foreach ( Engine_Registry::ai_engines() as $id => $engine ) {
            if ( isset( $rows[ $id ] ) ) { continue; }
            $rows[ $id ] = array(
                'label' => (string) ( $engine['label'] ?? $id ),
                'mode' => 'none',
                'status' => '暂无公开通用 URL 提交 API',
                'description' => '当前通过 GEO 内容质量、实体一致性、证据结构、可索引性与来源监测支持；插件不会伪造未公开的提交接口。',
            );
        }
        return $rows;
    }

    public static function for_engine( string $id ): array {
        $all = array_merge( self::search_engines(), self::ai_engines() );
        $row = $all[ $id ] ?? array();
        if ( isset( $row['via'] ) ) {
            $via = self::search_engines()[ (string) $row['via'] ] ?? array();
            if ( ! empty( $via['links'] ) ) {
                $row['links'] = array_merge( (array) ( $row['links'] ?? array() ), (array) $via['links'] );
            }
            if ( empty( $row['plugin_channel'] ) && ! empty( $via['plugin_channel'] ) ) {
                $row['plugin_channel'] = (string) $via['plugin_channel'];
            }
            if ( empty( $row['sitemap'] ) && ! empty( $via['sitemap'] ) ) {
                $row['sitemap'] = (string) $via['sitemap'];
            }
        }
        return $row;
    }

    public static function mode_label( string $mode ): string {
        return match ( $mode ) {
            'api' => 'API 主动提交',
            'indexnow' => 'IndexNow 主动通知',
            'sitemap' => 'Sitemap 提交',
            'webmaster' => '站长平台提交',
            'delegated' => '复用搜索引擎发现',
            'crawler' => 'Crawler 自动发现',
            default => '暂无公开主动接口',
        };
    }
}
