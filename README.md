# Aikeyboost SEO&GEO (Free)

[中文说明](#中文说明) · [English](#english) · [Releases](https://github.com/chinrobin6688-cloud/aikeyboost-seo-geo/releases)

## 中文说明

**Aikeyboost SEO&GEO** 是基于 WordPress 的免费 SEO 与 GEO 优化插件，支持 SEO 标题、Meta Description、Canonical、Schema、动态 Sitemap、robots.txt、多语言 hreflang 关联、404 / 重定向、内容诊断及可选的搜索引擎提交和 AI 分析。

### 安装

1. 在 [Releases](https://github.com/chinrobin6688-cloud/aikeyboost-seo-geo/releases) 下载完整插件 ZIP（不要直接使用 GitHub 自动生成的仓库 Source code ZIP）。
2. WordPress 后台 → 插件 → 安装插件 → 上传插件。
3. 激活插件，进入 **SEO&GEO → 基础设置** 检查选项。
4. 初次使用，请确认网站只由一个 SEO 插件负责输出前台元数据；仅在明确需要时启用“强制接管”。

**公开版默认行为：** 强制接管 SEO 关闭；前台开发者署名关闭；卸载时保留数据开启。升级已有安装会保留已保存的用户选择。外部搜索引擎提交和多语言关联等可选功能需按实际需要配置。

**适用环境：** WordPress 6.2+、PHP 8.0+。当前数据结构版本 17。

### 透明度与隐私

插件的基本 SEO 功能不要求注册 Aikeyboost 帐号。部分可选功能需要连接 DeepSeek、IndexNow、百度、Google Search Console、Bing Webmaster 或百度统计；连接、提交、分析或统计行为及相关数据按对应设置执行。请阅读插件内的 `readme.txt` 外部服务与隐私说明。

## English

Aikeyboost SEO&GEO is a free WordPress plugin for traditional SEO and AI-search-readiness (GEO), covering page metadata, XML sitemaps, Schema.org structured data, genuine multilingual page relationships, redirects, 404 diagnostics and optional provider integrations.

Download the complete installable plugin ZIP from [GitHub Releases](https://github.com/chinrobin6688-cloud/aikeyboost-seo-geo/releases). **Do not upload GitHub's automatically generated repository source archive**: the WordPress plugin must be packaged with the `aikey-seo-geo/` root directory. WordPress >= 6.2 and PHP >= 8.0 are required.

New installations do not force SEO takeover or insert developer attribution without opt-in. Existing saved site settings are preserved on upgrades. See [`aikey-seo-geo/readme.txt`](aikey-seo-geo/readme.txt) for external services and privacy disclosure.

### Project structure

- `aikey-seo-geo/`: complete installable Free plugin source (all features in this directory are free to use)
- `LICENSE`: GPL version 2 or (at your option) any later version
- `CHANGELOG.md`: public-release notes

No private development history or Pro extension source is included. Source modifications and distributions are permitted under GPLv2-or-later; preserve the license and applicable copyright notices.

### Disclaimer

SEO/GEO tools cannot guarantee indexing, ranking or citations in AI answers. Back up your site and test plugin upgrades in a staging environment first.
