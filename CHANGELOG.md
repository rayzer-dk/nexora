# Changelog

## 3.96.7 — 2026-10-10

Hidden products stay hidden, itemised partial return receipts, forum warnings and moderator log.

- Catalog: a product hidden from the catalog no longer appears in "Related" and "Complementary" blocks (manual and automatic), in category product counts, in filter facets or in the sitemap. It stays reachable by its direct link.
- PRRO: a partial-refund receipt now lists real goods. It uses the items of a return request when their refunds add up to the refunded amount, or whole units of a single order line; otherwise it keeps the single "Partial refund" line.
- Forum: members can be warned (1-3 points, expiring). Three active points ban the member automatically for seven days. A new "Warnings" tab in the forum admin lists and revokes them.
- Forum: every ban, warning, hide, restore and delete by the shop team or a topic moderator is written to a moderator log (new "Moderator log" tab).
- Migration Version20261229100000 adds mc_forum_warning and mc_forum_mod_log; moderation keeps working before it runs.
- Not run in this release: the full E2E/CI suite (accumulated for one pass).

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
