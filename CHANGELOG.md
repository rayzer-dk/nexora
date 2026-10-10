# Changelog

## 3.97.1 — 2026-10-10

Product card, first pass: fewer fields, real controls instead of text codes.

- Prices by customer group are rows: pick a group from the list, enter the price, add as many rows as there are groups. Quantity price breaks are rows too ("from quantity" and price) instead of a "5=450, 10=420" text.
- "Related products" and "Bought together" are filled through a search box (by name or article) with chips, and a "Suggest from the category" button; the stored value is still the list of articles.
- The "Old price" field is gone from the form (the stored value is kept); the sale price with its start and end date is the way to run a discount.
- Rarely used fields (page address, unit, GTIN, MPN, purchase mode, button text, delivery note) are folded into "More options".
- Characteristics no longer show technical codes.
- New admin endpoint /admin/catalog/products/lookup (product search for the pickers).

## 3.97.0 — 2026-10-10

Languages in the URL.

- The default language stays at the root ("/catalog"); every other language has its own prefix ("/ru/catalog", "/en/catalog"; "/en-us/" and "/en-gb/" when two languages share a code). The prefix is cut off before routing, so every page works in every language, and generated links, forms, redirects and plain "/path" links of the page carry it again.
- "?lang=ru-RU" is now a one-time switch: it sets the language cookie and redirects to the language address. A returning visitor who chose another language is sent from the root to the prefixed address (pages only; forms, AJAX, files and feeds are not redirected).
- Every product, category, article and the home page carries hreflang links for all its languages (with x-default), and the canonical address of a prefixed page is the prefixed one. The sitemap lists prefixed addresses for non-default languages, and robots.txt blocks the private pages in every language.
- The language switcher links directly to the same page's address in the other language (the product's own address when it has one).
- Admin: the translation page of a product or category has an editable page address (URL) for each language; changing it keeps the old address as a redirect.
- Last-known-good page cache is kept per language; the canonical-host redirect keeps the language prefix.

## 3.96.9 — 2026-10-10

Interface fixes from the first review round.

- Product page: badges (sale, new, discount) sit on the photo, like in the catalog card, not next to the title.
- Admin security: copy buttons for the TOTP secret and for all recovery codes.
- Admin tables: the "Actions" column and its header are centred.
- Admin tabs are now clearly visible: bordered buttons, the active one filled with the accent colour.
- Admin forms: the Save button is repeated in the page header, so the long forms do not need scrolling to save.
- Category tree: folded on the first visit, then each branch stays open or folded as the admin left it; a drag handle marks rows that can be moved.
- The separate "Image ALT" admin page is gone: photos without their own ALT text get it from the name automatically on the storefront.

## 3.96.8 — 2026-10-10

Forum: reasons for hidden messages, reports reach moderators, topic header, slow mode.

- Hiding a message now takes a reason (storefront moderators and admin). The author gets an e-mail with the reason and a quote of the message, and still sees the hidden message with the reason in the topic (nobody else does).
- A report on a message from the topic now also e-mails the moderators of that topic (the admin queue is unchanged).
- Topic header: moderators of the topic and the admin can pin a formatted note (rules, summary, links) above the messages on every page of the topic.
- Slow mode: a topic can allow one message per member every N seconds; moderators of the topic are exempt. Set by a moderator in the topic or by the admin.
- Migration Version20261230100000 adds mc_forum_post.hidden_reason, mc_forum_topic.header_text and mc_forum_topic.slow_mode_seconds. Run migrations before opening forum topics.
- Header and slow-mode changes are written to the moderator log.
- Not run in this release: the full E2E/CI suite (accumulated for one pass).

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
