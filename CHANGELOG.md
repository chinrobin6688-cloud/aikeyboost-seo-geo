# Changelog

## v2.3.10 — 2026-11-20

- The GitHub distribution now automatically emits a visible Aikeyboost footer credit, with no corresponding settings checkbox; existing saved opt-outs are no longer honored.
- On GeneratePress, priority-1 `generate_before_footer_content` places Aikeyboost before other footer content. Other themes use priority-1 `wp_footer` as a best-effort fallback.
- Preserve other developer credits, theme copyright, legal notices and page content. Keep one-time output, no tracking calls, and standalone isolated CSS.
- Remove the old backend checkbox, the obsolete setting default and its write path without deleting existing stored WordPress options.
- No SEO/GEO feature changes, database migration or Pro code. 72 PHP, 7 JS, 10 CSS and 23 dedicated regression assertions passed; full live WordPress integration is not yet complete.
- GitHub-only distribution, not eligible for WordPress.org Directory as-is due to mandatory front-end credits.

## v2.3.9 — 2026-11-20

- Optional Aikeyboost frontend credit now **adds a separate line** and never replaces theme copyright or footer widgets.
- Uses GeneratePress `generate_after_footer` with a standard `wp_footer` fallback, guarded to output at most once per request.
- Enqueues a small isolated CSS file only when the saved credit setting is enabled.
- Preserves existing settings, all SEO/GEO features, PHP/WordPress support baseline and DB version 17. No Pro code added.
- Adds a standalone footer hook regression smoke test (20 assertions).

## v2.3.8 — 2026-11-20

- Publish the **entire original v2.3.6 private stable functionality**, with no Pro extension included.
- Restore all three original default-checked options for **new installations**: force SEO output takeover, retain data after uninstall, and optional Aikeyboost frontend attribution.
- Update third checkbox label to `自愿在网站前台显示 Aikeyboost 署名链接（默认开启）`.
- Highlight the plugin's original focus on Chinese WordPress site builders, Chinese search platforms, and multilingual export websites.
- Preserve previously saved site settings on updates. No feature logic change or database schema migration (DB 17).
- GitHub distribution only: optional frontend credit enabled by default is not WordPress.org directory-compatible.

## v2.3.7

- First isolated public-source GitHub release based on the v2.3.6 stable edition.
- Free features and Prism visual interface included; default optional attribution and SEO takeover were OFF in this release.

## v2.3.6

- All three Basic & Compatibility settings default ON for new installations; upgrades preserve saved choices.
- Stable Prism admin UI; schema and core SEO/GEO functionality unchanged.
