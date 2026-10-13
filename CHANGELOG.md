# Changelog

## Unreleased (next after 3.97.23)

Customisable dashboard, carrier keys in the admin, replies to returns, options and variants, test fixes. Checked on PHP 8.4 + MySQL 8.4 + Chromium (changed specs pass); the full CI pass is still to do.

- Dashboard: "Customise" lets each administrator drag the blocks (or move them with the arrows) and hide or show them; the layout is saved per administrator and can be reset to the default. Migration Version20261231130000 adds mc_admin_dashboard_layout.
- Carriers: the keys of every delivery service (Nova Post, Ukrposhta, Delivery, DHL, GLS, Meest) can be typed on the Carriers page. They are stored in var/config/carrier-secrets.json (outside the web root), are never shown again and win over the same names in .env. Saving needs the system-settings permission.
- Returns: "Reply to the customer" sends a message by e-mail without changing the status and writes it to the return history; history events and statuses are translated.
- Options and variants: three-step strip (options, create variants, set price and stock), quick-start presets (colour, size, memory, material, gift wrapping), a counter of the variants that will be created, compact variant cards.
- Language bar: SEO templates, the landing page and notification templates use the shared language bar.
- Media settings: a warning appears when the server's PHP cannot write AVIF; the preset then still serves WebP.
- Tests brought in line with the code: dashboard order (quick actions above the figures), own redirects (first form of the page), option fields (the test adds an option if the product has none), sale price instead of the hidden old-price field, mobile payment radio (no forced click under the sticky bar), category SEO (waits for the save redirect), appearance colours (sets the light scheme first), AVIF (skipped on a server without an AVIF encoder).
- Drag and drop (a grip handle on each item, also movable with the arrow keys on the focused handle): option values, options and extra choices of a product; delivery and payment methods (the checkout now follows the chosen order); menu items (saved at once, within the same parent); characteristics; benefit cards and the landing page lists (features, steps, reviews, FAQ). The page builders already had it.
- Test run on a real database (MySQL 8.4, PHP 8.4, Chromium): the changed specs pass; the upload spec waits for its lazy script (marker data-media-ready); the option and SEO-template specs no longer depend on one product id or on the first language tab.
- Public API v1, write side: products, stock, prices and orders can be created and updated with API keys (new scopes), idempotent, with OpenAPI; keys are managed in the admin.
- Instalments: monobank "Покупка частинами" and PrivatBank "Оплата частинами" as payment methods (webhooks and bank polling); keys of payment services are typed on System → Integrations → Connections (stored in var/config/integration-secrets.json, never shown again). Not verified against the live banks: the code follows their public documentation and is tested against stand-ins.
- Import from other platforms: Shopify, WooCommerce, PrestaShop, Prom.ua, Horoshop and any product table (CSV/XLSX/XML) with a dry run, a queue for the photos, 301 redirects and rollback. Migration Version20261231140000.
- Order connections: every new or changed order can be sent to a CRM (KeyCRM, SalesDrive, Bitrix24 presets) or any address with an editable JSON template, a signed request (HMAC) and a secret header; a log of the sends. Migration Version20261231150000. The preset field names are best-effort; check them against the CRM.
- Telegram and Viber for customers: after the order the customer opens the shop's bot with a one-time link and receives every status of that order; /stop switches it off. Never linked by phone number. Webhooks are registered from the Connections page. Migration Version20261231160000 adds mc_messenger_link.
- Marketplaces (Prom.ua): orders from Prom become shop orders once each (matched by the offer id of the Prom feed or by SKU, with the Prom price and delivery text and a note in the order), stock and price are pushed only when they change, and the shop status goes back to Prom ("received", "delivered", "canceled"). Keys and switches on System → Integrations → Connections → Marketplaces, a "Run now" button, a log, the scheduler task marketplace_sync every 5 minutes and the command commerce:marketplace:sync. New marketplaces are one class implementing MarketplaceAdapterInterface. Migration Version20261231170000. Written from the public Prom documentation and tested against a stand-in, not against a live Prom shop; Rozetka and Epicentr stay on their feeds until their seller API access can be checked.
- FOP package (Admin → FOP: income limit): income of the year (payments received by the date of payment, less refunds) against the limit of the single-tax group (167 / 834 / 1167 minimum wages, the minimum wage and an own limit are editable because the figures change every year), the percent used, the room left, a forecast for year end, income by month and quarter, a warning on the dashboard when the warning level is reached, and a CSV book of income for any period for the accountant. A working tool, not tax advice. New order document "Видаткова накладна" (delivery note, numbered VN-yyyy-nnnnnn) next to the invoice and the packing slip. Migration Version20261231180000.
- Store director (Admin → Director, its own switch, off by default): every day it proposes up to three actions from the shop's own numbers, the most useful first: unanswered inquiries, orders waiting for payment, fast-selling products about to run out, products that have not sold for two months. The list is made once a day and shown on the dashboard. The one action that changes data is a reversible sale price (size set on the page, old price kept, "Undo" gives it back unless the price was changed meanwhile); the others open the place to act. The rules are plain and run inside the shop; no language model is used. Migration Version20261231190000.
- VAT / OSS (Admin → VAT / OSS): VAT collected by the country of the buyer per quarter (orders, net, VAT, gross, refunds) with a CSV, the base of a One-Stop-Shop return; and a check of an EU VAT number in VIES (valid, name, address; clear answers for an invalid number, an unavailable service and nonsense input). A helper report, not a tax return. The VIES request follows the public REST description and is tested against a stand-in; the address can be changed with VIES_API_BASE.
- Stripe in Europe: a new optional setting STRIPE_PAYMENT_METHODS (Connections → Payments → Stripe), for example card,ideal,blik,klarna,sepa_debit, asks Stripe Checkout for those methods (iDEAL, BLIK, Klarna, SEPA debit and other local methods); empty keeps Stripe's own choice from the dashboard. Each method must also be switched on in the Stripe dashboard and suit the order currency.
- VAT number of a business buyer at checkout: it is checked in VIES when the order is placed (never blocks the order, never changes the price); the order page shows whether a zero rate (reverse charge) can apply: valid number of another EU country, the shop's own country, invalid, VIES unavailable, or not an EU number. Migration Version20261231200000.
- Reverse charge (Admin → VAT / OSS, off by default): when it is on and VIES confirms the VAT number of a business of another EU country given at checkout, the VAT of the goods is taken off the order total (the order records vat_relief_minor and the mode reverse_charge), the invoice shows "VAT removed (reverse charge)" with the note and the buyer's number, and the checkout tells the buyer about it. A domestic, invalid, unreachable or non-EU number leaves the VAT as it is. The VAT of the delivery is not separated, so check the setting with your accountant. Migration Version20261231220000.
- Upgrade: before it reads the files, upgrade.php repairs directories the web server cannot open (0755) and unreadable files (0644), a common result of unpacking over FTP or with Windows tools; the number of repaired entries goes to var/log/upgrade.log.
- Admin and storefront polish: popovers built on <details> (help tips with the question mark, switchers, menus, column picker) close on a click or tap outside, on Escape, and when another one opens; the question mark is centred on the line of its heading; icon buttons in table cells are always one row, or a compact block of two rows (2 columns for 4 icons, 3 for 5-6), also where the template did not wrap them (a script groups them). Google feed: variants of one product share item_group_id, a single item has none; items without GTIN and MPN carry identifier_exists=no.
- Storefront: a setting "Background under product photos" (Appearance → Storefront; none by default, or white) is applied to every place a photo is shown (cards, gallery, related, cart, compare, search); the dark theme no longer paints white tiles by itself. The home hero shows the same picture in the light and the dark theme. Carousels (product sliders) go round without a jump back to the start (the slides are repeated and the track is moved by one set while it is still; data-loop="off" keeps a strip with ends). Filter lists keep a gap between the values and the scrollbar. Checkout builder: the shipping block can hide the region choice and the city / branch search separately (the buyer then types one address); Twig switches written as |default(true) ignored a "false" and are fixed (checkout company block, home categories block).
- Order board (Orders → Order board): the orders as columns (New, Preparing, Ready for pickup, Shipped, Delivered in the last 14 days); a card is dragged to another stage (or moved with its select, also with the keyboard) and the status changes exactly as on the order page, with the same notice to the customer; a refused move puts the card back and says why. Waybill stickers: select orders in the list and press "Print stickers" for a 100 x 100 mm page per order with the recipient, delivery point, cash-on-delivery amount, comment and a Code 128 barcode of the waybill number (or the order number when there is none).
- Quick view: every product card has an eye button that opens the product in a dialog (photo, price, availability, the description of the product page, add to cart) without leaving the list; Escape or a click outside closes it, buying closes it and updates the cart.
- Product card from a photo (product form, with an AI provider switched on): upload a JPEG, PNG or WebP photo (up to 4 MB) and an optional hint; the model drafts the name, short and full description and notes with the brand and characteristics, and the form fills in; nothing is saved until the person saves, and the instruction forbids inventing a model, size, material or certificate. Works with Claude, OpenAI and Gemini (providers from extensions need to implement ImageTextGenerationProviderInterface). Tested against the request formats of the three providers with stand-ins, not against the live services.
- Quick add (Products → Quick add): one screen for a new product with the photo, name, price, stock, category and texts (the photo helper of the assistant fills the name and texts from the picked photo); the product is created as a draft and opens in the full form for the rest.
- Affiliate programme (Marketing → Affiliate programme): partners with a link ?ref=CODE; the visitor carries the code in a 30-day cookie, an order in that time earns the partner a percentage of the goods (not the delivery), a partner earns nothing on their own orders (same e-mail), unknown or paused codes are ignored. The page shows visits, orders, sales, pending / due (completed orders) / paid commission per partner and a "Pay out" action that records the payout (the money is sent by the owner). Migration Version20261231210000.
- Banner carousel (home builder): neighbouring banners with the new switch "Show as a slide of the banner carousel" become one carousel instead of a row, like the banner module of OpenCart: swipe, arrows, dots, autoplay every N seconds (0 = off, default 5, set on the first slide) that stops on hover, focus, a touch, a hidden tab, reduced motion and data saving; slides out of sight are not reachable with the keyboard. Banners without the switch work as before. The first picture of a carousel is loaded with high priority.
- PageSpeed (mobile product page, 99/100 before): the main product photo is preloaded from the head, so it no longer waits for the stylesheets; accessibility findings fixed: the thumbnail strip is a group, the shop link and the rating link have accessible names that contain their visible text. Still open from that report: the 30 KB storefront stylesheet is mostly unused on one page (a per-page split would cut about 25 KB and 0.3 s of render blocking), and the related-product photos are slightly larger than needed.
- Storefront fixes from the owner's list: checkout and registration ask for "Surname and first name" (new text full_name); the checkout page has no newsletter block and no footer columns (a quiet line only); the newsletter block is shown only on the pages ticked in Appearance → Storefront → Footer (default: home, blog, pages; never on product, catalog, cart); after a swipe in the product gallery the next tap opened the viewer only on the third try (a leftover one-time click guard after each swipe, now a 350 ms guard); in the cart and the slide-in cart a tap on "+" or "−" of one line cancelled the pending update of another line (one shared timer, now one per line) and a stale transition handler could hide the drawer after it was opened again; the catalog button of the header always shows the word "Каталог".
- Admin fixes from the owner's list: subscribers show the source in words and whether the address belongs to a customer who ordered or only subscribed (new filter "Type"); the VIES page says that VIES covers EU numbers only and where Ukrainian numbers are checked; traffic lists (sources, pages, entry pages, campaigns) can show 10, 25, 50 or 100 rows; the media picker has numbered pages and the total instead of "Load more"; the header name of the shop can be switched off (logo only) with "Show the shop name next to the logo"; the colours of the top strip stand in one row; English is drawn with the American flag everywhere; feed centre: language tabs with flag and name, currency chosen from the shop's currencies, copy button next to the URL; notification centre: statuses, channels and types in words; gift cards: card controls moved to their own full-width row (the narrow cell broke the table) and the loyalty form explains how points are earned and spent; the content-language select in the admin header shows the language name.
- Home builder: product blocks can be a grid or a carousel and choose their list (catalogue order, recommended = best sellers, new arrivals) and how many; several product blocks can be added; a new block "Recently viewed" can be placed anywhere (shops that never placed it keep it at the bottom); banners and banner carousels can run across the whole window ("Full window width" on the first banner). Automation rules: the text is a multi-line field with 18 variables (items, phone, e-mail, comment, payment, link and others) inserted with a click and ready-made example messages.
- Date and time fields (admin forms and product add-ons): a styled calendar with month arrows, today, a time selector and "Clear" replaces the browser's own picker; the value keeps the same format, typing still works, `data-native-picker` on an input keeps the browser's picker.
- Still open: the contrast test (needs its findings file from a run), Russian admin catalogue, other browsers in CI, vector search (skipped on request), extra columns for supplier price lists.

## 3.97.23 — 2026-10-10

- Browser test for product controls follows the customer-group select. Last full Chromium run: 387 passed, 15 failed; after the fixes above and re-runs only the known older failures remain (contrast, AVIF picture, own redirects, option price action, dashboard order, mobile payment radio, old price field).

## 3.97.22 — 2026-10-10

Browser-test run (Chromium): the tests that still expected the old forms were brought in line with the new ones.

- Admin forum topic page: the actions of a message (hide with reason, delete, ban) sit in one row and no longer overlap.
- Tests follow the intended changes: no code field in customer groups and custom fields (made from the name), SEO URL inside the folded "More" block of the product form, badge background and text colour pickers, mandatory reason when a moderator hides a message, checkout form scoped to avoid the call-back form fields.

## 3.97.21 — 2026-10-10

Bugs found by the first run on PHP 8.4 with a real database.

- Saving a product or category translation failed (the address field of the language was not passed into the transaction) - fixed; this also broke the demo installation.
- The file library routes had no permission mapping - now they follow the media permissions (view / manage).

## 3.97.20 — 2026-10-10

Full verification run.

- Lint errors in the admin script fixed (unused values and argument).
- Checked: typecheck, eslint (0 errors), stylelint, asset build, all tools/check-*.mjs, all bin/*-check.php that need no database, Twig lint, PHP syntax of every file.
- Checked on a clean MariaDB 10.11: all 124 migrations apply in order (including the new 2026-12-29..31 ones); forum schema and OpenCart import runtime checks pass.
- Not runnable in the build sandbox (PHP 8.3 only, the project needs 8.4): PHPUnit (258 of 279 pass, the other 21 stop on PHP 8.4 syntax), kernel boot, Playwright E2E.

## 3.97.19 — 2026-10-10

OpenCart import no longer stops on bad records.

- A record with errors (one product out of a thousand) no longer blocks the whole import: it is skipped, everything else is loaded.
- After the import the page lists every skipped record - type, source id and the reason - so it can be fixed in the old shop or added by hand.

## 3.97.18 — 2026-10-10

Carriers in one place, waybill weight counted automatically.

- New "Carriers" page (under Shipments): every delivery service with what it can do (rates, waybills, tracking, returns), whether it is connected, and which server settings it still lacks - names only, values are never shown. A note says where the keys live (the .env file written by the installer).
- Order page: the weight for the Nova Poshta waybill is the sum of the products' weights; a warning appears when some products have no weight.

## 3.97.17 — 2026-10-10

Fixes found by the project's own static checks (run once over the work of 3.96.7-3.97.16).

- Checks run and clean: Twig syntax of all 261 templates, icons, CSS coverage, design tokens, i18n/template keys, runtime string audit, order documents, forum, security, XSS, SEO baseline, UX, system completeness, release contract.
- Fixed: an icon that was not in the built icon set, a dynamic translation key (now an explicit list), a non-standard breakpoint, a raw font size, classes without styles, README headings (the logo now comes after the version heading), three untranslated error texts, the order-document check now looks for the PDF code in its new place.

## 3.97.16 — 2026-10-10

- Product questions: the answer is e-mailed also to guests who left only an e-mail address (before, only registered customers got it).

## 3.97.15 — 2026-10-10

Shipments list: dates, search, sorting, export.

- Shipments: filter by date range and by tracking number, order number or customer; sort by creation or update date, status, order or carrier (either direction); download the filtered list as CSV. The filters sit in one compact row.

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
