# Aikeyboost SEO&GEO — 为中国 WordPress 建站用户设计的免费开源插件

**面向中国建站者、中小企业官网、中文 SEO 服务商与外贸多语言独立站。** 提供中国搜索引擎相关设置、传统 SEO 与 AI 搜索可引用性（GEO）管理，同时兼顾国际搜索引擎。

[下载安装包 v2.3.8](https://github.com/chinrobin6688-cloud/aikeyboost-seo-geo/raw/refs/heads/main/dist/aikey-seo-geo-v2.3.8.zip) · [GitHub Releases](https://github.com/chinrobin6688-cloud/aikeyboost-seo-geo/releases) · [English](#english)

## 核心功能

- **中国搜索引擎适配：** 百度、搜狗、360、神马等平台的抓取策略、站点验证与来源识别；提供百度主动提交、IndexNow（适用平台）等可选功能。部分平台没有稳定公开的通用提交 API，插件不会伪造提交能力。
- **SEO 基础：** SEO Title、Meta Description、Canonical、robots 索引指令、Open Graph、Schema.org、XML Sitemap、robots.txt、404 监控及重定向。
- **GEO 内容管理：** SEO/GEO 诊断评分、事实与来源管理、FAQ、`llms.txt`、爬虫监测，以及由站长主动配置的 DeepSeek AI 建议。
- **海外/多语言站：** 已有译文页面的 ID 关联、hreflang、语言切换、Google/Bing 搜索相关配置等。插件不自动翻译文章内容。
- **运营工具：** 发文管理、基础自动优化、百度统计（需配置）、联系表单与企业地图链接。

## 版本与完整功能说明

**v2.3.8 完整保留私有稳定版 v2.3.6 的全部功能代码**，并不是删减版或演示版；本次只调整版本说明、GitHub 开源文档和“基础与兼容”第三个选项的显示文字。**不包含单独开发中的 Pro 扩展模块**。

### 「基础与兼容」三个选项

新安装时，下面三项**全部默认勾选**，管理员可以取消：

1. **即使检测到其他 SEO 插件也强制由 Aikeyboost 输出前台 SEO**。同时启用其他 SEO 插件可能造成重复 SEO 标签，请按实际情况选择。
2. **卸载时保留 Aikeyboost 数据**。
3. **自愿在网站前台显示 Aikeyboost 署名链接（默认开启）**。此项可取消且不影响 SEO/GEO 功能；只对插件支持的主题页脚中匹配的已有署名内容进行替换，不保证在所有主题自动新增署名。

**升级须知：** 已经保存过的开启/关闭状态会保留，不会强行覆盖。已有站点若原来关闭了第1或第3项，请在后台「SEO&GEO → 基础设置」主动勾选并保存。

## 安装方式

1. 下载[完整 WordPress 安装 ZIP](https://github.com/chinrobin6688-cloud/aikeyboost-seo-geo/raw/refs/heads/main/dist/aikey-seo-geo-v2.3.8.zip)。
2. WordPress 后台 → 插件 → 安装插件 → 上传插件 → 安装并启用；更新已有插件时建议先备份网站和数据库。
3. 检查「基础与兼容」三个选项，确认网站没有不需要的重复 SEO 输出。

**要求：** WordPress 6.2+、PHP 8.0+；数据库结构版本 17，未增加新表。

**注意：** 请使用上面的 `dist/aikey-seo-geo-v2.3.8.zip` 完整安装包。GitHub 自动生成的 **Source code ZIP** 是仓库源码压缩包，不能直接作为 WordPress 安装包。

## 开源协议与发布说明

- 许可证：[GPL-2.0-or-later](LICENSE)，项目源码免费开放。
- 该版本**通过 GitHub 分发**。由于前台署名默认开启，不符合 WordPress.org 对前台署名默认关闭/主动选择的要求，**不得原样提交 WordPress.org 插件目录**。
- 基本 SEO 功能无需注册 Aikeyboost 账号；外部 AI、搜索提交和统计功能需管理员主动配置，相关外部请求与隐私信息参阅 [`readme.txt`](aikey-seo-geo/readme.txt)。
- 无法保证搜索引擎收录、关键词排名或被 AI 产品引用。

## English

**Aikeyboost SEO&GEO** is a free, GPL-licensed WordPress plugin **designed for Chinese website builders and SEO operators**. It combines technical SEO for Chinese search engines (including Baidu, Sogou, 360 and Shenma-related configuration) with global XML sitemaps, Schema.org markup, multilingual hreflang relationships and optional GEO diagnostics. Some search services do not offer a stable submission API; the plugin does not claim otherwise.

Version **2.3.8** includes the **complete feature set of the stable private v2.3.6 edition**, but **does not bundle separately developed Pro-extension code**. On new installs, three Basic & Compatibility checkboxes default to ON: force SEO takeover, retain data on uninstall, and optional frontend attribution. Existing saved site choices are preserved on upgrade. Enabling SEO takeover alongside other SEO plugins can cause duplicate metadata. Frontend attribution can always be disabled without affecting core functions; the setting only replaces specific existing theme credits.

Download the **[installable plugin ZIP](https://github.com/chinrobin6688-cloud/aikeyboost-seo-geo/raw/refs/heads/main/dist/aikey-seo-geo-v2.3.8.zip)**, not GitHub's automatic source archive. Requires WordPress 6.2+ and PHP 8.0+. This GitHub-only release is **not eligible for direct WordPress.org directory submission with frontend attribution enabled by default**.
