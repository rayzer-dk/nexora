# Changelog

## 3.97.14 — 2026-10-10

Import from OpenCart without a command line, compact variant editor.

- Migration centre: a form to import from an OpenCart / ocStore 3 shop right in the admin (shared hosting has no console): database server, name, user, password (used for the request only, never saved), table prefix, optional folder with the old photos. "Check" only reads and shows counts and problems; "Import" runs only when the check has no errors. The command-line tool stays.
- Product variants: each variant shows SKU, price, stock and status; unit, GTIN, MPN, weight and size are folded into "More options" (same for adding a variant).

## 3.97.13 — 2026-10-10

Badge colours: background and text.

- Product badges (new, sale, bestseller, your own): a free colour now has a background picker and a text picker, each with its HEX box shown once (the page used to add a second, confusing HEX box next to the picker). A live preview shows the badge. An empty text colour is chosen automatically by contrast.
- Migration Version20261231120000 widens the badge tone column so the text colour fits ("#background:#text").

## 3.97.12 — 2026-10-10

Subcategory tiles and coloured service cards.

- Storefront: subcategories on a category page are photo tiles (grid) again; the page always rendered them as small round chips because of a wrong default.
- Customer service page: the five overview cards are coloured by state with icons (the same card standard as the order page).

## 3.97.11 — 2026-10-10

One language bar per page, B2B overview.

- Pages with many per-language fields (delivery and payment methods, benefits): a single language bar at the top of the page, instead of one bar for every method.
- B2B: coloured overview cards (companies, waiting for approval, price lists, turnover, unpaid); the table header no longer breaks the column alignment; the company edit form opened inside the table keeps within the page width.

## 3.97.10 — 2026-10-10

Import progress and a fuller export.

- Import wizard: "apply" runs in steps of 100 rows with a progress bar that shows how many rows are done, created, updated and failed, and lists the first errors. "Preview" works as before. (Matching the file's columns to the shop's fields, with suggestions and saved profiles, was already in the wizard.)
- Product export: new columns - category names, page address (url), h1, meta title, meta description, the main photo and all photos (relative addresses). The importer ignores the columns it does not know, so an export can be edited and imported back.

## 3.97.9 — 2026-10-10

Text editor and spelling.

- The rich text editor offers an emoji palette (a 😊 button with about 80 common emoji that are inserted as plain text) instead of the inline-icon picker; icons already placed in old texts still display.
- Spell checking: every large text field of the admin (letters, descriptions, notes) gets the browser's spell checker, in the language of the content (the language tab or the page language). Code fields are left alone.

## 3.97.8 — 2026-10-10

Quick order is visible, customer requests are answered from the request.

- Quick order: switched on by default; the window asks for name and phone, plus an optional e-mail and comment. The comment arrives with the request.
- Customer requests (admin): compact cards instead of a wide table, coloured status cards with an "All" tab, the product photo and link, tap-to-call and mail links, the order of the request kept (new first).
- Answer from the request: "Reply" sends the text to the customer by e-mail in the shop's letter style or by SMS (when the SMS gateway is on); the request moves to "In progress" and a dated note records how it was answered.
- Request statuses are translated.

## 3.97.7 — 2026-10-10

Customer groups you can actually use.

- A customer's group is chosen from the list of real groups (with their names) in the customer card and in the customer list; the list filter offers every group, also the ones with no customers yet. Before, it was a free text field and only groups that already had customers could be filtered.
- A new group needs only a name (the technical code is made from it); the group list no longer shows codes, the customer count links to the filtered customer list.
- The page now explains where a group works: after sign-in, in the customer card, and as per-product group prices.

## 3.97.6 — 2026-10-10

Fewer technical fields, price lists from a file.

- Extra product fields: only the name and the type are asked; the technical code is built from the name (no need to invent "warranty_months"), and the code and sort columns are gone from the list.
- Suppliers: a price list can be uploaded from the computer (XML, YML or CSV up to 100 MB) instead of a link; it is stored privately and used by every run until a new file replaces it. A link is still possible.

## 3.97.5 — 2026-10-10

Confirmation page and readable codes.

- E-mail confirmation: the link opens its own result page (confirmed / link invalid) with buttons to the account, the sign-in and the shop, instead of dropping the visitor on the home or login page.
- Order events: the details cell shows labelled values (status changes, amounts in money, yes/no) instead of raw JSON; dates are shortened to minutes.
- Returns: status, reason and resolution are translated in the list and in the return card; attribute types ("text", "decimal", ...) are translated in the catalog attributes form.

## 3.97.4 — 2026-10-10

Orders, invoices and shipments.

- Order page: the four top cards are coloured by state (order status, payment, amount, customer) with icons; ordered items show the product photo; quantities no longer carry six zeros ("2" instead of "2.000000") in orders, invoices, e-mails, the cart, checkout and the product list.
- Invoices and packing slips: the shop logo and a small photo of each product are embedded (works in the PDF too); the delivery data reads as the carrier plus city and address instead of raw JSON; document type and date are readable in the order's document list.
- One click "Send to customer" next to every document: the PDF goes to the customer's e-mail as an attachment with a ready subject and text.
- Shipments: the registration form asks only for the carrier (a list) and the tracking number; the rest is under "More options". A hand-typed tracking number can be corrected with the pencil icon; the change is dated and signed ("edited ..."), written to the shipment history and copied to the delivery record. Numbers created by the carrier cannot be edited by hand.
- Migration Version20261231110000 adds mc_shipment.tracking_edited_at and tracking_edited_by.

## 3.97.3 — 2026-10-10

New Nexora logo.

- New brand files in public/assets/branding: the mark (nexora-mark.png, 192/512 and a maskable 512), the horizontal and stacked logos with transparent background, each in a dark-text and a light-text (nexora-logo-light) version, favicon.ico (16-64 px), favicon-32.png and the 180 px touch icon.
- Used in the admin (sidebar, sign-in and two-step pages, tab icon), the installer and upgrade pages, the storefront's default tab icon, the web app manifest (real 192 and 512 icons plus a maskable one when the store has no icon of its own), and the README.
- The old SVG mark and logo are removed.

## 3.97.2 — 2026-10-10

File libraries for product documents and digital downloads.

- New "Files" page in the admin (next to the photo library) with two libraries: documents (PDF/TXT, public) and digital downloads (private). Folders, search, new folder, upload from the computer.
- The product form no longer asks for a file from the computer: "Choose a document" and "Choose a file for download" open a window with the same library (folders, search, new folder, upload). A file uploaded in that window is chosen at once. One file can be attached to any number of products.
- A new digital product opens on its "Digital files" section right after it is created, with a prompt to add the file.
- Migration Version20261231100000 adds mc_file_folder and mc_file_item. The old direct upload still works for existing forms and integrations.

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
