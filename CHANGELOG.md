# Changelog

## 3.96.6 — 2026-10-06

PHP 8.4 CSV deprecations removed.

- fgetcsv() and fputcsv() calls in supplier feeds, product feeds, catalog import/export, universal import, admin audit/stock-request exports and analytics export now pass the $escape parameter explicitly (empty string, RFC 4180), so PHP 8.4 raises no deprecation and the behaviour stays the same on PHP 9.
- Forum: the destructive "delete message" form no longer uses an inline onsubmit handler (blocked by CSP); the storefront runtime confirms any form with data-confirm.
- Admin: Demo data and PRRO controllers no longer show raw exception messages for unexpected errors; they log the exception and show a generic message.
- Checks: forum-community-v2-check expects the real post vote route (storefront_forum_post_vote); i18n-template-check enforces only uk-UA and en-US (other catalogs are not maintained for now).
- Test suite: PendingMigrationsTest no longer errors in tearDown when pdo_sqlite is missing and the test is skipped.

## 3.96.5 — 2026-10-06

Browser installer writes the canonical-host and supplier-host settings.

- The installer now includes COMMERCE_CANONICAL_REDIRECT and COMMERCE_SUPPLIER_ALLOW_PRIVATE_HOSTS in the generated environment file (required by the production release check).
