# Changelog

## v2.3.11 — 2026-11-20

- Same-version footer correction: use GeneratePress `generate_credits` to render inside the existing copyright/ICP bar after other credits, without an extra footer section.
- Remove the detached `wp_footer` visible block. Other themes use an existing-footer-only DOM fallback; keep one-time rendering, inherit theme colors, and preserve copyright/content.
- Update regression tests to verify footer and legal text are retained and appear before the Aikeyboost credit, across supported PHP versions.
- Full free SEO/GEO feature set and database version 17 unchanged; no Pro extension or migration.
- This GitHub edition is not intended for the WordPress.org Plugin Directory without adapting its attribution behavior.
