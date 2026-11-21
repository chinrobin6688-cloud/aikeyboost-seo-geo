# Changelog

## v2.3.11 — 2026-11-20

- Fix standalone Aikeyboost front-end credit placement: render at the latest `wp_footer` priority after theme footer, copyright and ICP/legal notices on compliant themes.
- Remove early GeneratePress-specific footer hook; keep the one-time rendering guard, isolated CSS and no changes to theme copyright or content.
- Update regression tests to verify footer and legal text are retained and appear before the Aikeyboost credit, across supported PHP versions.
- Full free SEO/GEO feature set and database version 17 unchanged; no Pro extension or migration.
- This GitHub edition is not intended for the WordPress.org Plugin Directory without adapting its attribution behavior.
