# Changelog

Nexora Commerce has a single product line on the `main` branch. Only release-level changes are listed.

## 3.57.0 — 2026-10-05

Fixes for MySQL 8.4 and the release gates.

- Migrations for the order "edited" mark and scheduled campaigns now run on MySQL 8.4 as well as MariaDB.
- Messages of the Stripe, PayPal, DHL and GLS integrations are translated.
- Admin activity log: date range filter and CSV export.
