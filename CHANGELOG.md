# Changelog

## v2.3.11 — 2026-11-20

- Same-version footer correction: append the attribution as an inline span on the same GeneratePress copyright and ICP line using `generate_credits`, inheriting the original typography.
- Remove obsolete credit stylesheet, standalone layout overrides and redundant enqueue code. Other themes use an existing-copyright-line-only fallback; preserve copyright and registration content.
- Update PHP 8.0/8.2/8.3 regression tests to verify inline markup, no duplicate credit, safe fallback and absence of obsolete CSS.
- Full free SEO/GEO feature set and database version 17 unchanged; no Pro extension or migration.
- This GitHub edition is not intended for the WordPress.org Plugin Directory without adapting its attribution behavior.
