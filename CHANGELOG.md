# Changelog

Nexora Commerce has a single product line on the `main` branch. Only release-level changes are listed.

## 3.63.1 — 2026-10-05

Fixes for the release checks.

- Own redirects, the 404 log and the link check tables now use the same collation as all other tables (the database check failed on a fresh install).
- Admin search: letters of the keyboard-layout tables are written as escapes, so the release contract (no hard-coded Cyrillic in runtime code) passes.
