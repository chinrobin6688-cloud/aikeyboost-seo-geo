=== Aikeyboost SEO&GEO ===
Contributors: aikeyboost
Tags: seo, sitemap, schema, multilingual, ai-search
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.3.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Designed for Chinese WordPress site builders: Baidu and Chinese search SEO/GEO, metadata, sitemaps, schema, multilingual hreflang and optional global search integrations.

== Description ==

Aikeyboost SEO&GEO is designed specifically for WordPress website builders in China: small business websites, Chinese-language corporate websites, Chinese SEO service providers and companies expanding overseas. It combines locally useful Chinese search-engine settings with established WordPress SEO, multilingual site management and optional AI-search-readiness workflows.

为中国 WordPress 建站用户专门设计，兼顾中国大陆网站与外贸多语言独立站。聚焦百度、搜狗、360 搜索、神马和中国 AI 搜索生态的可抓取性、站点验证、网站内容与实体信息，同时支持 Google、Bing、IndexNow、Sitemap、Schema 和 hreflang。部分平台没有公开的通用主动提交 API，插件不会虚构这些接口；GEO 优化不保证被 AI 引用或获得排名。

Core features:
* Edit SEO titles, descriptions, canonical URLs, robots indexing directives and social sharing metadata.
* Build XML sitemaps with indexability checks for public posts, pages and supported custom post types.
* Publish Organization, WebSite, Article, WebPage, Product, Breadcrumb and eligible visible FAQ structured data.
* Register languages and explicitly link actual translated WordPress page IDs. Hreflang output remains disabled until you enable the appropriate migration settings.
* Configure language-specific Organization details without rewriting the canonical organization identifier.
* Manage redirects and view aggregated 404 activity.
* View basic search referral and known crawler counters stored in your WordPress database.
* Review SEO/GEO content metadata and display manually supplied, public-facing facts, FAQs and sources.
* Provide /llms.txt and bounded /llms-full.txt text endpoints. These do not guarantee inclusion in any AI answer.
* Configure optional search-engine integrations, including IndexNow and Baidu URL submission when applicable. New installations do not automatically submit URLs until the administrator enables submission.
* Optionally connect your own DeepSeek API key to request AI analysis.

There are no paid license restrictions on the functionality distributed with this plugin. Optional independently distributed premium extensions or substantial hosted services may be offered separately by the developer. They are not required for the included features.

== Installation ==

1. Install the plugin through WordPress Plugins, or upload the plugin ZIP through Plugins > Add New.
2. Activate Aikeyboost SEO & GEO and open its settings.
3. Review the sitemap and SEO metadata settings for your WordPress installation.
4. For multilingual sites, register only real languages and link existing translated pages before enabling hreflang.
5. Connect external search or AI services only if you intend to use them. Do not activate overlapping SEO metadata modules in multiple SEO plugins.
6. Review the Basic & Compatibility options for SEO takeover and data retention. Both default to enabled for new installations; existing saved choices are preserved.

== Frequently Asked Questions ==

= Does this plugin automatically rank my site in AI search? =
No. No plugin can guarantee a citation, ranking or mention in an AI-generated answer. The plugin helps manage publicly available content and technical SEO metadata.

= Does it translate page content? =
No. It links genuine existing translations and manages SEO language metadata; it does not automatically translate pages.

= Are external services required? =
No. Basic SEO, schema and sitemap functions run without an external Aikeyboost account. Some optional integrations need provider credentials or approved site verification.

= Can this plugin coexist with another SEO plugin? =
On new installations, Aikeyboost SEO takeover is enabled by default even if another SEO plugin is detected. This may duplicate SEO tags or sitemap output. Disable takeover or the other SEO plugin to avoid conflicts. Existing saved takeover choices are respected.

== External Services ==

The plugin includes optional connections to third-party services. Please review each provider's terms and privacy policy before enabling them.

* DeepSeek: When an administrator configures an API key and requests AI analysis, selected article text and analysis context are transmitted to the configured API endpoint.
* IndexNow and Baidu: When URL submission is configured and active, published URLs, site verification values or submission keys may be transmitted to the respective search platform. Review automatic submission settings before publishing.
* Google Search Console and Bing Webmaster APIs: Administrator-authorized integrations retrieve webmaster analytics data from the provider.
* Baidu Analytics: Its remote tracking script is available only if explicitly enabled/configured. Website visitors may then send tracking information directly to Baidu.
* Public domain verification or on-demand diagnostics may make HTTP requests to relevant site URLs at an administrator's request.

The plugin also stores local WordPress contact form submissions, aggregated referral/crawler counts, SEO metadata and configured provider credentials. Site administrators are responsible for explaining visitor data processing under applicable privacy rules, and for reviewing retention and deletion requirements.

== Privacy ==

Referral/crawler statistics are stored locally as aggregate source, path, hit and timestamp information in plugin tables. Contact forms retain the details users choose to submit in the WordPress database. Optional external connections may transfer site URLs, API credentials or content to their respective providers as described above. The plugin does not guarantee that an external provider will treat this information as confidential. Review the privacy notices of services that you enable.

== Screenshots ==

1. SEO/GEO optimization overview.
2. Search engine and sitemap controls.
3. Multilingual page translation groups.
4. Structured data and public content settings.

Screenshots illustrate the administration workflow; actual appearance may vary by theme and WordPress setup.

== Changelog ==

= 2.3.11 =
* Improve compatibility with the GeneratePress footer layout and simplify unused frontend assets.
* Keep existing SEO/GEO modules, multilingual connections and database schema version 17 unchanged.
* Validate PHP 8.0, 8.2 and 8.3 compatibility and WordPress ZIP package integrity.
