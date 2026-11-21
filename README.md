# Aikeyboost SEO&GEO — 专为中国站长打造的 WordPress SEO / GEO 免费开源插件

**让 WordPress 网站更方便地管理百度 SEO、国际搜索引擎优化，以及 AI 搜索时代的内容与技术基础。**

Aikeyboost SEO&GEO 是一款面向中国 WordPress 建站用户开发的免费开源 SEO 与 GEO 插件。

它将常用的 WordPress SEO 设置、中国搜索引擎相关配置、网站内容诊断、结构化数据、多语言 SEO 和 AI 搜索优化辅助工具整合在同一个后台，适合中国企业官网、外贸独立站、中小企业站长、SEO 从业者及 WordPress 建站服务商使用。

**核心功能无需购买 Pro 版本，也无需注册 Aikeyboost 账号。** 部分 AI 分析及第三方搜索服务需要用户自行配置相应的账号或 API 凭据。

[免费下载完整插件](https://github.com/chinrobin6688-cloud/aikeyboost-seo-geo/raw/refs/heads/main/dist/aikey-seo-geo-v2.3.8.zip) · [查看最新版本](https://github.com/chinrobin6688-cloud/aikeyboost-seo-geo/releases) · [查看开源许可证](LICENSE)

## 为什么开发这款插件？

许多中国用户使用 WordPress 建站时，需要同时考虑两个市场：

**中国大陆市场**：百度、搜狗、360 搜索、神马搜索及国内 AI 搜索生态。

**国际市场**：Google、Bing，以及不同语言和地区的搜索需求。

传统 WordPress SEO 工具通常可以解决通用的 Title、Meta、Sitemap 等问题，但中国用户仍需另外处理部分本土搜索引擎配置、内容本地化、网站实体信息及多语言关联。

Aikeyboost SEO&GEO 希望帮助站长在同一套后台中，有条理地管理这些工作，减少重复配置，让 SEO 和 GEO 优化更容易长期执行。

## 核心功能

### 1. 中国搜索引擎 SEO 优化

针对中国大陆网站的实际需求提供相关功能：

- 百度、搜狗、360 搜索和神马搜索的爬虫识别与抓取策略管理。
- 百度站长工具相关配置、站点验证和可选的主动提交。
- 中国搜索平台来源识别及搜索爬虫访问统计。
- 搜索引擎抓取规则与 robots.txt 管理。
- 支持符合适用平台要求的 IndexNow URL 提交。

**说明：** 各搜索平台的公开接口能力不同。插件不承诺向所有中国搜索引擎直接提交 URL，也不保证提交后一定被收录。

### 2. 完整的 WordPress 基础 SEO 管理

覆盖网站日常 SEO 工作中的主要技术配置：

- SEO Title 与 Meta Description。
- Canonical URL 与 robots 索引指令。
- Open Graph 社交分享信息。
- XML Sitemap 与 Sitemap 索引。
- 页面、文章及受支持自定义内容类型的收录管理。
- robots.txt 内容管理。
- Schema.org 结构化数据，包括 Organization、WebSite、Article、WebPage、Product、Breadcrumb 等适用类型。
- 404 错误监控、重定向管理。
- SEO 数据诊断与问题检查。

帮助站长集中管理网站的技术 SEO 基础。

### 3. GEO 与 AI 搜索优化辅助

GEO（Generative Engine Optimization，生成式引擎优化）关注网站内容在 AI 搜索和生成式回答场景中的可发现性、可理解性与可信度。

插件提供：

- SEO/GEO 页面诊断与评分。
- 企业和品牌实体信息管理。
- 内容核心事实、来源链接与 FAQ 信息管理。
- `llms.txt` 和 `llms-full.txt` 文本端点。
- 搜索引擎及已知 AI 爬虫的相关识别与监测。
- 可选的 DeepSeek AI 内容分析与优化建议。
- 页面 SEO 标题、描述、关键词及内容结构的辅助检查。

插件强调真实内容和可验证信息，不鼓励虚构数据、企业资质、客户案例或不存在的 FAQ。

**特别说明：** GEO 功能是内容管理与技术优化辅助工具，不代表能够直接控制 ChatGPT、DeepSeek、豆包等 AI 平台的回答结果，也不保证获得 AI 引用。

### 4. 全球多语言 SEO

适合建设中国站和海外多语言独立站的用户。

- 注册和管理不同语言及地区代码。
- 将已经存在的真实翻译页面建立对应关系。
- 根据有效关联生成 hreflang 标签。
- 管理多语言企业实体信息。
- 配合 Sitemap、Canonical 和页面 SEO 设置管理国际网站。
- 提供语言切换相关支持。

支持中文、英文、法语、德语、阿拉伯语、俄语等符合要求的语言代码及地区变体。

插件不会自动把文章翻译成其他语言，需要先创建对应的真实语言页面。

### 5. SEO/GEO 优化工作台与运营工具

除了技术配置，还提供常用的网站管理功能：

- 页面 SEO/GEO 诊断与优化建议。
- 基础自动评分和自动优化规则。
- 文章发布与编辑管理。
- 基于 WordPress 原生内容结构保存文章。
- Google Search Console 和 Bing Webmaster 数据的可选连接与查看。
- 网站来源和搜索爬虫统计。
- 企业地图入口与联系表单工具。
- 部分现有 SEO 数据的迁移辅助。

适用于企业日常网站维护，也适合 SEO 服务商统一开展客户网站的基础优化工作。

## 适合哪些用户？

**中国企业网站管理员**：管理中文官网、百度 SEO、网站内容与结构化数据。

**外贸企业和独立站运营人员**：兼顾 Google/Bing 搜索需求与多语言 SEO。

**WordPress 建站人员**：为企业网站配置 SEO 基础设施，减少重复操作。

**SEO/GEO 服务商**：开展网站诊断、元数据管理、页面优化与长期维护。

**个人站长**：使用免费的开源工具管理常见 SEO/GEO 设置。

## 免费、开源、功能完整

Aikeyboost SEO&GEO 采用 **GPL-2.0-or-later** 开源许可证。

公开版 v2.3.8 完整保留了私有稳定版 v2.3.6 的已有功能，不是演示版，也没有对已公开的功能设置付费授权限制。

当前公开仓库不包含另外开发中的 Pro 扩展模块；公开版包含的全部功能均可免费使用。

项目源码公开，用户可以依据许可证查看、修改和再分发。

## 下载与安装

**当前版本：v2.3.8**

环境要求：WordPress 6.2 或更高版本，PHP 8.0 或更高版本。

1. [下载完整 WordPress 插件 ZIP](https://github.com/chinrobin6688-cloud/aikeyboost-seo-geo/raw/refs/heads/main/dist/aikey-seo-geo-v2.3.8.zip)。
2. 进入 WordPress 后台「插件 → 安装插件 → 上传插件」。
3. 上传 ZIP、安装并启用。
4. 进入「SEO&GEO → 基础设置」，检查并调整适合自己网站的配置。

请下载完整插件安装包，不要直接使用 GitHub 自动生成的仓库 Source code ZIP。

### 安装时需要注意

公开版 v2.3.8 在全新安装时，以下三个选项默认开启：

- 强制由 Aikeyboost 输出前台 SEO。
- 卸载时保留 Aikeyboost 数据。
- 自愿在网站前台显示 Aikeyboost 署名链接。

三个设置均可由网站管理员调整，升级不会覆盖之前已保存的选择。

如果网站已经安装其他 SEO 插件，建议检查 Title、Meta、Canonical、Sitemap 等是否重复输出，避免 SEO 配置冲突。

前台署名选项可以关闭，不影响插件的主要 SEO/GEO 功能。该 GitHub 发行版的署名默认设置不符合 WordPress.org 插件目录要求，不应未经调整直接提交其官方目录。

## 常见问题

**Aikeyboost SEO&GEO 是免费的吗？**

是的。当前公开仓库提供的功能可以免费使用，并遵循 GPL 开源许可证。部分可选第三方 AI 或搜索服务可能需要用户自备账号或承担相应服务费用。

**适合专门做百度 SEO 的中国网站吗？**

适合。插件包含百度相关配置、主动提交及中国搜索引擎抓取策略管理等功能，但不能保证搜索排名或收录效果。

**支持 ChatGPT、DeepSeek 等 AI 搜索优化吗？**

提供与 GEO 相关的内容诊断、实体信息、事实来源、FAQ 和 AI 辅助建议等工具，但不保证被任何指定 AI 平台引用或推荐。

**支持海外多语言网站吗？**

支持真实页面之间的语言关联和 hreflang 管理，但不提供自动翻译文章的功能。

**必须使用付费 Pro 版本吗？**

不需要。公开版内已经包含的功能不依赖 Pro 扩展。

## English

### Aikeyboost SEO&GEO — Free Open-Source WordPress SEO & GEO Plugin for Chinese Websites

Aikeyboost SEO&GEO is a free and open-source WordPress plugin designed for Chinese website builders, business websites, SEO professionals, and multilingual international websites.

It combines Baidu SEO and Chinese search engine configuration with technical SEO, XML Sitemaps, Schema.org structured data, multilingual hreflang management, SEO/GEO diagnostics, and optional AI-assisted content optimization using DeepSeek.

The plugin supports site owners who need to manage Chinese search visibility and international SEO in one WordPress installation.

All included features are available under the GPL-2.0-or-later license. Optional external provider credentials may be required for certain integrations.

**Requirements:** WordPress 6.2+, PHP 8.0+.

[Download Plugin](https://github.com/chinrobin6688-cloud/aikeyboost-seo-geo/raw/refs/heads/main/dist/aikey-seo-geo-v2.3.8.zip) · [Releases](https://github.com/chinrobin6688-cloud/aikeyboost-seo-geo/releases) · [License](LICENSE)

*SEO and GEO tools can help improve website structure and content management, but they cannot guarantee search rankings, indexing, or mentions in AI-generated answers.*

---

**Developed by [Aikeyboost](https://aikeyboost.com/) — 专注 WordPress SEO、GEO 与中国企业网站优化。**
