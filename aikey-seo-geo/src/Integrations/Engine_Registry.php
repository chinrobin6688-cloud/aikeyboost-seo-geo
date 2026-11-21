<?php
namespace AIKEY\SeoGeo\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Central registry for search engines, AI answer/search products and content-search ecosystems.
 *
 * Important: some Chinese AI products do not publish a stable, dedicated crawler UA.
 * Those platforms are still covered for referral attribution and GEO audit targeting, but we
 * deliberately do not invent robots.txt tokens for them.
 */
final class Engine_Registry {
    public static function platforms(): array {
        return array_merge( self::search_engines(), self::ai_engines(), self::content_ecosystems() );
    }

    public static function search_engines(): array {
        return array(
            'baidu' => array(
                'label' => '百度搜索', 'group' => 'search', 'region' => 'CN',
                'bots' => array( 'Baiduspider', 'Baiduspider-render', 'Baiduspider-image', 'Baiduspider-video', 'Baiduspider-news' ),
                'verification_name' => 'baidu-site-verification', 'referrers' => array( 'baidu.com' ),
                'coverage' => 'Sitemap、站点验证、百度主动提交、抓取监测、SEO/GEO 审计',
            ),
            'sogou' => array(
                'label' => '搜狗搜索', 'group' => 'search', 'region' => 'CN',
                'bots' => array( 'Sogou web spider' ), 'verification_name' => 'sogou-site-verification',
                'referrers' => array( 'sogou.com' ), 'coverage' => 'Sitemap、站点验证、抓取监测、SEO/GEO 审计',
            ),
            'so360' => array(
                'label' => '360搜索', 'group' => 'search', 'region' => 'CN',
                'bots' => array( '360Spider', '360Spider-Image', '360Spider-Video' ), 'verification_name' => '360-site-verification',
                'referrers' => array( 'so.com', 'haosou.com' ), 'coverage' => 'Sitemap、站点验证、抓取监测、SEO/GEO 审计',
            ),
            'shenma' => array(
                'label' => '神马搜索', 'group' => 'search', 'region' => 'CN',
                'bots' => array( 'YisouSpider' ), 'verification_name' => 'shenma-site-verification',
                'referrers' => array( 'sm.cn' ), 'coverage' => 'Sitemap、站点验证、移动搜索兼容、抓取监测',
            ),
            'bing' => array(
                'label' => '必应 Bing', 'group' => 'search', 'region' => 'CN/Global',
                'bots' => array( 'bingbot' ), 'verification_name' => 'msvalidate.01',
                'referrers' => array( 'bing.com', 'cn.bing.com' ), 'coverage' => 'Sitemap、站点验证、IndexNow、抓取监测',
            ),
            'google' => array(
                'label' => 'Google', 'group' => 'search', 'region' => 'Global',
                'bots' => array( 'Googlebot' ), 'verification_name' => 'google-site-verification',
                'referrers' => array( 'google.com', 'google.com.hk' ), 'coverage' => 'Sitemap、站点验证、抓取监测、Schema',
            ),
            'toutiao_search' => array(
                'label' => '头条/抖音搜索生态', 'group' => 'search', 'region' => 'CN',
                'bots' => array( 'Bytespider' ), 'verification_name' => 'bytedance-verification-code',
                'referrers' => array( 'toutiao.com', 'douyin.com' ),
                'coverage' => '站点验证兼容、共享抓取标识监测、实体/内容可引用性优化',
                'note' => 'Bytespider 为字节系共享抓取标识，不能等同于某一个具体产品。',
            ),
            'chinaso' => array(
                'label' => '中国搜索 / 国搜', 'group' => 'search', 'region' => 'CN',
                'bots' => array(), 'referrers' => array( 'chinaso.com' ),
                'coverage' => 'Sitemap/可抓取性兼容、来源识别、实体与权威信源优化',
                'note' => '纳入中国公开网页搜索覆盖；当前不写入未经公开确认的专用 crawler UA。',
            ),
        );
    }

    public static function ai_engines(): array {
        return array(
            'deepseek' => array(
                'label' => 'DeepSeek', 'group' => 'ai', 'region' => 'CN', 'bots' => array(),
                'referrers' => array( 'chat.deepseek.com', 'deepseek.com' ),
                'coverage' => 'GEO 审计目标、引荐识别、HTML/实体/事实/证据可引用性优化',
                'note' => '当前不写入未经官方公开确认的专用 crawler UA。',
            ),
            'doubao' => array(
                'label' => '豆包', 'group' => 'ai', 'region' => 'CN', 'bots' => array(),
                'referrers' => array( 'doubao.com' ), 'coverage' => 'GEO 审计目标、引荐识别；字节系共享 Bytespider 在“头条/抖音搜索生态”统一管理',
                'note' => '不把共享 Bytespider 伪装成豆包专用 crawler，避免错误归因。',
            ),
            'kimi' => array(
                'label' => 'Kimi', 'group' => 'ai', 'region' => 'CN',
                'bots' => array( 'KimiBot', 'Kimi-SearchBot', 'Kimi-User' ),
                'referrers' => array( 'kimi.com', 'kimi.moonshot.cn' ),
                'coverage' => '官方公开 crawler robots 控制、抓取监测、引荐识别、GEO 审计目标',
            ),
            'yuanbao' => array(
                'label' => '腾讯元宝', 'group' => 'ai', 'region' => 'CN', 'bots' => array(),
                'referrers' => array( 'yuanbao.tencent.com' ),
                'coverage' => 'GEO 审计目标、引荐识别、腾讯生态实体一致性优化',
            ),
            'qwen' => array(
                'label' => '通义千问 / Qwen', 'group' => 'ai', 'region' => 'CN', 'bots' => array(),
                'referrers' => array( 'chat.qwen.ai', 'qwen.ai', 'tongyi.com', 'qianwen.com' ),
                'coverage' => 'GEO 审计目标、引荐识别、阿里搜索生态可读性优化',
            ),
            'quark' => array(
                'label' => '夸克 AI 搜索', 'group' => 'ai', 'region' => 'CN', 'bots' => array(),
                'referrers' => array( 'quark.cn' ),
                'coverage' => 'GEO 审计目标、引荐识别、移动端/结构化内容优化',
            ),
            'baidu_ai' => array(
                'label' => '百度文心 / 百度 AI 搜索', 'group' => 'ai', 'region' => 'CN', 'bots' => array(),
                'referrers' => array( 'yiyan.baidu.com', 'wenxin.baidu.com' ),
                'coverage' => 'GEO 审计目标、引荐识别；底层网页发现同时受百度搜索可抓取性影响',
            ),
            'nami_ai' => array(
                'label' => '360 纳米 AI / 360 AI 搜索', 'group' => 'ai', 'region' => 'CN', 'bots' => array(),
                'referrers' => array( 'bot.n.cn', 'n.cn', 'ai.360.cn' ),
                'coverage' => 'GEO 审计目标、引荐识别；同时兼顾 360 搜索抓取',
            ),
            'metaso' => array(
                'label' => '秘塔 AI 搜索', 'group' => 'ai', 'region' => 'CN', 'bots' => array(),
                'referrers' => array( 'metaso.cn' ), 'coverage' => 'GEO 审计目标、引荐识别、可引用段落/证据优化',
            ),
            'zhipu' => array(
                'label' => '智谱清言', 'group' => 'ai', 'region' => 'CN', 'bots' => array(),
                'referrers' => array( 'chatglm.cn', 'zhipuai.cn' ), 'coverage' => 'GEO 审计目标、引荐识别',
            ),
            'spark' => array(
                'label' => '讯飞星火', 'group' => 'ai', 'region' => 'CN', 'bots' => array(),
                'referrers' => array( 'xinghuo.xfyun.cn', 'sparkdesk.cn' ), 'coverage' => 'GEO 审计目标、引荐识别',
            ),
            'tiangong' => array(
                'label' => '天工 AI', 'group' => 'ai', 'region' => 'CN', 'bots' => array(),
                'referrers' => array( 'tiangong.cn' ), 'coverage' => 'GEO 审计目标、引荐识别',
            ),
            'hailuo' => array(
                'label' => '海螺 AI / MiniMax', 'group' => 'ai', 'region' => 'CN', 'bots' => array(),
                'referrers' => array( 'hailuoai.com', 'hailuoai.video', 'minimax.io' ), 'coverage' => 'GEO 审计目标、引荐识别',
            ),
            'zhihu_zhida' => array(
                'label' => '知乎直答 / 知乎全网搜索', 'group' => 'ai', 'region' => 'CN', 'bots' => array(),
                'referrers' => array( 'zhida.zhihu.com', 'zhihu.com' ),
                'coverage' => 'GEO 审计目标、来源识别、权威信源/可溯源内容优化',
                'note' => '知乎已提供直答与面向大模型的全网搜索能力；不伪造未公开的站点 crawler UA。',
            ),
            'tencent_ima' => array(
                'label' => '腾讯 ima', 'group' => 'ai', 'region' => 'CN', 'bots' => array(),
                'referrers' => array( 'ima.qq.com' ),
                'coverage' => 'GEO 审计目标、腾讯生态内容/知识信源准备、来源识别',
                'note' => 'ima 具备全网信源搜索/知识检索能力；当前不写入未公开的独立 crawler UA。',
            ),
            'openai' => array(
                'label' => 'ChatGPT', 'group' => 'ai', 'region' => 'Global',
                'bots' => array( 'GPTBot', 'OAI-SearchBot', 'ChatGPT-User' ),
                'referrers' => array( 'chatgpt.com', 'chat.openai.com' ), 'coverage' => 'crawler 控制、抓取监测、引荐识别、GEO 审计目标',
            ),
            'claude' => array(
                'label' => 'Claude', 'group' => 'ai', 'region' => 'Global',
                'bots' => array( 'ClaudeBot', 'Claude-SearchBot', 'Claude-User' ),
                'referrers' => array( 'claude.ai' ), 'coverage' => 'crawler 控制、抓取监测、引荐识别、GEO 审计目标',
            ),
            'perplexity' => array(
                'label' => 'Perplexity', 'group' => 'ai', 'region' => 'Global',
                'bots' => array( 'PerplexityBot', 'Perplexity-User' ),
                'referrers' => array( 'perplexity.ai' ), 'coverage' => 'crawler 控制、抓取监测、引荐识别、GEO 审计目标',
            ),
            'google_ai' => array(
                'label' => 'Gemini / Google AI', 'group' => 'ai', 'region' => 'Global',
                // Google-Extended is a robots.txt product token, not a distinct HTTP User-Agent.
                'bots' => array( 'Google-Extended' ), 'detect_bots' => array(), 'referrers' => array( 'gemini.google.com' ),
                'coverage' => 'AI 使用偏好 robots 控制、引荐识别、GEO 审计目标',
                'note' => 'Google-Extended 是 robots.txt 产品令牌，没有独立 HTTP User-Agent；因此不会作为独立 crawler 写入抓取监测。',
            ),
            'copilot' => array(
                'label' => 'Microsoft Copilot', 'group' => 'ai', 'region' => 'Global', 'bots' => array(),
                'referrers' => array( 'copilot.microsoft.com' ), 'coverage' => '引荐识别、GEO 审计目标；网页发现同时受 Bing/IndexNow 影响',
            ),
        );
    }

    public static function content_ecosystems(): array {
        return array(
            'wechat_search' => array(
                'label' => '微信搜一搜', 'group' => 'ecosystem', 'region' => 'CN', 'bots' => array(),
                'referrers' => array( 'weixin.qq.com' ),
                'coverage' => '作为中国内容搜索生态纳入实体一致性/站外证据检查；不伪造公开站点 crawler/API',
            ),
            'xiaohongshu_search' => array(
                'label' => '小红书搜索', 'group' => 'ecosystem', 'region' => 'CN', 'bots' => array(),
                'referrers' => array( 'xiaohongshu.com' ),
                'coverage' => '站外实体一致性/品牌证据覆盖；不作为开放网页搜索 crawler 处理',
            ),
            'bilibili_search' => array(
                'label' => '哔哩哔哩搜索', 'group' => 'ecosystem', 'region' => 'CN', 'bots' => array(),
                'referrers' => array( 'bilibili.com' ),
                'coverage' => '站外实体一致性/品牌证据覆盖；不作为开放网页搜索 crawler 处理',
            ),
        );
    }

    public static function default_enabled(): array {
        return array_fill_keys( array_keys( self::platforms() ), true );
    }

    public static function default_crawler_policies(): array {
        $out = array();
        foreach ( self::platforms() as $id => $platform ) {
            if ( ! empty( $platform['bots'] ) ) { $out[ $id ] = 'allow'; }
        }
        return $out;
    }

    public static function default_verifications(): array {
        $out = array();
        foreach ( self::search_engines() as $id => $platform ) {
            if ( ! empty( $platform['verification_name'] ) ) { $out[ $id ] = ''; }
        }
        return $out;
    }

    public static function enabled_platforms(): array {
        $settings = \AIKEY\SeoGeo\Core\Settings::section( 'china_engines' );
        $enabled = isset( $settings['enabled'] ) && is_array( $settings['enabled'] ) ? $settings['enabled'] : self::default_enabled();
        return self::merge_bool_defaults( self::default_enabled(), $enabled );
    }

    public static function is_enabled( string $id ): bool {
        $enabled = self::enabled_platforms();
        return ! empty( $enabled[ $id ] );
    }

    public static function search_labels(): array {
        $labels = array();
        foreach ( self::search_engines() as $id => $platform ) {
            if ( self::is_enabled( $id ) ) { $labels[] = $platform['label']; }
        }
        return $labels;
    }

    public static function ai_labels(): array {
        $labels = array();
        foreach ( self::ai_engines() as $id => $platform ) {
            if ( self::is_enabled( $id ) ) { $labels[] = $platform['label']; }
        }
        return $labels;
    }

    public static function detect_crawler( string $user_agent ): array {
        $ua = strtolower( $user_agent );
        if ( '' === $ua ) { return array(); }
        foreach ( self::platforms() as $id => $platform ) {
            if ( ! self::is_enabled( $id ) ) { continue; }
            $detect_bots = array_key_exists( 'detect_bots', $platform ) ? (array) $platform['detect_bots'] : (array) ( $platform['bots'] ?? array() );
            if ( empty( $detect_bots ) ) { continue; }
            foreach ( $detect_bots as $bot ) {
                if ( false !== strpos( $ua, strtolower( $bot ) ) ) {
                    return array( 'id'=>$id, 'label'=>$platform['label'], 'group'=>$platform['group'], 'bot'=>$bot );
                }
            }
        }
        return array();
    }

    public static function source_from_host( string $host ): string {
        $host = strtolower( preg_replace( '/^www\./', '', $host ) ?? $host );
        if ( '' === $host ) { return ''; }
        // More specific AI/product domains are checked before broad search-engine domains.
        foreach ( array( self::ai_engines(), self::content_ecosystems(), self::search_engines() ) as $group ) {
            foreach ( $group as $id => $platform ) {
                if ( ! self::is_enabled( $id ) ) { continue; }
                foreach ( (array) ( $platform['referrers'] ?? array() ) as $needle ) {
                    $needle = strtolower( preg_replace( '/^www\./', '', (string) $needle ) ?? (string) $needle );
                    if ( $host === $needle || str_ends_with( $host, '.' . $needle ) ) { return (string) $platform['label']; }
                }
            }
        }
        return '';
    }

    private static function merge_bool_defaults( array $defaults, array $values ): array {
        foreach ( $defaults as $key => $default ) {
            $defaults[ $key ] = array_key_exists( $key, $values ) ? (bool) $values[ $key ] : (bool) $default;
        }
        return $defaults;
    }
}
