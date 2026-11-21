=== Aikeyboost SEO&GEO ===
Contributors: aikeyboost
Tags: seo, sitemap, schema, multilingual, ai-search
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.3.9
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
6. In the public GitHub edition, all three Basic & Compatibility checkboxes are ON by default on new installations: force SEO takeover, keep data on uninstall, and optional Aikeyboost footer attribution. Review them in Basic Settings; you can disable either optional output at any time. Previously saved choices are preserved on upgrade.

== Frequently Asked Questions ==

= Does this plugin automatically rank my site in AI search? =
No. No plugin can guarantee a citation, ranking or mention in an AI-generated answer. The plugin helps manage publicly available content and technical SEO metadata.

= Does it translate page content? =
No. It links genuine existing translations and manages SEO language metadata; it does not automatically translate pages.

= Are external services required? =
No. Basic SEO, schema and sitemap functions run without an external Aikeyboost account. Some optional integrations need provider credentials or approved site verification.

= Will an Aikeyboost link appear on my visitors' pages? =
On a new installation, the optional attribution setting starts enabled. When enabled, the plugin adds a separate line containing the Aikeyboost attribution near the end of the page; it no longer replaces any existing theme copyright text. On GeneratePress the line is added inside the theme footer; other themes receive the line through the standard WordPress wp_footer hook. The plugin outputs the attribution only once per page even if both hooks fire. The administrator can disable it in Basic Settings without affecting SEO/GEO. Existing saved disabled choices remain disabled after updating.

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

Screenshots are planned for the WordPress.org assets repository after obtaining approval.

== GitHub public distribution note ==

This GitHub source edition intentionally keeps the v2.3.6 stable defaults: the two optional output settings (forced SEO takeover and front-end attribution) start ON for new installations. They are individually configurable, and explicit saved choices remain unchanged on upgrades. When enabled, the plugin outputs its own small attribution line; it does not replace the theme copyright or widget content. The output is limited to one instance per request and requires a theme that invokes the standard wp_footer hook or the GeneratePress footer hook.

IMPORTANT: This edition is distributed via GitHub and must not be submitted unchanged to the WordPress.org Plugin Directory. WordPress.org requires visitor-facing credits and links to be OFF by default and opt-in. The default SEO takeover may conflict with other SEO plugins. Review these settings before using the plugin on a production site.

== Changelog ==

= 2.3.9 =
* Render voluntary Aikeyboost attribution as a new independent line rather than replacing GeneratePress or widget copyright text.
* Prefer GeneratePress footer insertion with one-time wp_footer fallback for other WordPress themes. Do not duplicate output if both hooks fire.
* Load a tiny, isolated frontend CSS stylesheet only when attribution is enabled; preserve saved opt-outs.
* Keep all v2.3.8 SEO/GEO features and database version 17 unchanged.

= 2.3.8 =
* Publish the complete v2.3.6 stable feature set on GitHub without any Pro-only extension.
* Restore the v2.3.6 defaults on new installs: forced SEO takeover ON, data retention ON, voluntary Aikeyboost footer credit ON. Preserve previously saved choices.
* Clarify the third checkbox's user-facing label; no front-end SEO behavior changes.
* Clarify China-focused WordPress SEO/GEO positioning in the plugin description and documentation.
* Database version remains 17; the private development repository and Pro modules remain excluded.

= 2.3.6 =
* Default the three Basic & Compatibility checkboxes to enabled on new installations: SEO takeover, retain data on uninstall, and optional frontend Aikeyboost attribution.
* Preserve previously saved on/off settings on upgrade and honor explicit checkbox changes thereafter.
* Keep database schema version 17 and all existing SEO/GEO functionality unchanged. Private distribution only: WordPress.org requires public-site credit links to be off by default.

= 2.3.5 =
* Preserve v2.3.2 stable functionality with the integrated Prism administration design.
* Correct notice and page-subtitle CSS ownership so warnings, errors, success and information messages remain readable on light cards and dark headers.
* Remove the previous ineffective helper-class style overrides. No database, shortcode, frontend, provider or feature change.


= 2.3.3 =
* Update the plugin administration visual presentation with the Aikeyboost Prism styles: consistent panels, richer depth, responsive navigation and readable forms.
* Keep the complete v2.3.2 Free/stable feature set and all existing writable workflows unchanged. No Pro dependency, licensing gate, database migration or frontend asset change.
* Preserve all SEO/GEO keys, menu URLs, public routes and compatibility with WordPress 6.2 and PHP 8.0.


= 2.3.2 =
* Make optional visitor-facing developer attribution disabled by default, requiring explicit administrator consent.
* Prepare the public WordPress.org description and external-service/privacy disclosures.
* Preserve all existing SEO/GEO functionality and multilingual relationships.

= 2.3.1 =
* Add localized Organization/WebSite records, registered locale route recognition and a bounded /llms-full.txt endpoint.

= 2.3.0 =
* Introduce database-backed multilingual translation groups and opt-in ID-based hreflang.
