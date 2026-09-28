# Nexora Commerce 3.5.2

3.5.2 uses a clean Extension API 2.0 with no legacy Settings Schema v1 compatibility and adds bonus extension packs, commercial extension metadata, stronger storefront presets and multilingual catalog import/export. Extension API 2.0 with dynamic Builder blocks, extension routes/pages, stable UI slots, extension permissions, scoped assets, an extension-owned migration ledger, and Ed25519-signed Trusted Module Packages. Unsigned PHP/JS still cannot activate and extensions never patch Core files.


Release channel: `production`. Repository CI certifies source/package contracts and the supported database matrix. Live deployment certification (HTTP, load, accessibility and configured external APIs) remains environment-specific and is performed separately.


3.3.9 adds transactional gift cards and store-scoped loyalty accounts with checkout redemption, paid-order accrual, idempotent rollback and customer/admin reward views.


## B2B commerce

3.3.9 adds store-scoped company accounts, buyer/approver/admin company roles, VAT/tax identifiers, credit limits, payment terms, company price lists, quantity-tier prices, purchase-order references and approval workflow. B2B pricing is revalidated at checkout and deferred-payment orders use the same inventory lifecycle as the standard checkout.

## Builder and navigation improvements in 3.3.6

The visual builder now supports reusable validated sections and one-click block duplication. Navigation editing uses a direct edit workflow and a safe parent selector, while server-side tree validation rejects cross-menu parents and cyclic nesting.

## Nova Poshta API 2.0 in 3.3.3

The Ukrainian `nova_post` delivery profile now uses Nova Poshta API 2.0. City and warehouse Ref values flow from checkout into shipment creation. Server-only sender references and the API key enable automatic branch/parcel-locker EW creation, COD backward-money data, provider-confirmed cancellation, live tracking checks and optional proxied carrier PDF labels without exposing the API key to the browser. Courier-address EW creation remains a manual fallback until a dedicated address-Ref workflow is added.

Ukrposhta, Meest and Delivery Auto currently provide location/provider transport integration. They do not claim Nova Poshta-equivalent create/cancel/track/label shipment lifecycle yet; those carrier-specific shipment gateways remain integration work.


PHP 8.4+ modular commerce platform focused on Europe and Ukraine. The currently certified runtime range is PHP 8.4–8.5; newer stable branches are enabled only after platform QA.

The platform architecture keeps infrastructure complexity optional for small installations. It adds a Transactional Outbox domain-event bus with versioned event contracts and isolated subscriber delivery, contextual store-scoped RBAC, a Public REST API v1 foundation, Extension SDK v1 with declarative settings and encrypted secrets, and architecture boundary tests. The default storefront remains SSR-first and progressive; Redis/Valkey, external search, GraphQL, Docker/Kubernetes and full headless deployments stay optional accelerators or integration surfaces rather than boot requirements. The one-upload package contains the production runtime, vendor dependencies and compiled assets and passes the static release/security/performance gates. Stable/Production-certified status still requires real browser/runtime/load QA and sandbox certification of external integrations.
A browser installation/deployment path is available for production packaging. A production release is designed for one-archive upload with the domain document root pointed to `public/`; installation writes configuration and runs the same canonical preflight/migrations/seed path as CLI. No application files are moved after installation.

Repository QA and production packaging are certified in CI. Live deployment, load, accessibility and configured external API certification remain environment-specific.

## Current status
- Digital products: private file storage outside public, per-order download entitlements, payment-gated activation, download/expiry limits, and revocation after a full refund.

3.3.3 consolidates admin productivity and access hardening and adds real domain-routed multi-store context, persistent admin store/market context, operational returns, saved carts, comparison, Q&A/review submission, navigation management and a storefront UI translation layer: configurable columns and saved views for products/orders, quick preview drawers, browser-local Builder draft recovery, a privacy-safe administrator activity log, and strict store-scoped order administration. configurable columns and saved views for products/orders, quick preview drawers, browser-local Builder draft recovery, a privacy-safe administrator activity log, and strict store-scoped order administration.

3.5.2 is a production-channel release with CI-certified core runtime. External carrier/payment/live-load certification remains a separate release requirement. It adds server-side promotions/coupons, event-safe bulk actions, CSV import/export, unified notification operations, optional SMS, double-opt-in newsletter campaigns, and optional OpenAI/Gemini authoring while preserving the existing production hardening. The operational source-level platform now includes immutable release switching with rollback injection tests, Media Library and visual Home/Header/Footer/Product builders, resumable OpenCart/ocStore Migration Center, asynchronous Google Merchant and consent-aware Marketing Hub integrations, persistent integration retries/dead-letter handling, and the new security/performance hardening gates.

3.0.4 added SSRF protection, ZIP compression-ratio bomb detection, webhook replay protection, security regression and runtime failure-isolation gates. 3.0.5 added bounded storefront read caching and scale-oriented catalog indexes for the normal 5k–50k product range. 3.0.6 adds optional Meilisearch with automatic SQL fallback, incremental indexing, storefront read projections, precomputed facets, cursor/keyset traversal, opt-in Redis/Valkey scale profiles and database observability. 3.0.7 separates admin/storefront assets, adds lazy feature loading, bundle budgets and production precompression.

Remaining stable-release blockers are real populated-database load tests on MySQL and MariaDB, browser/device E2E on the supported matrix, and a certified PRODUCTION package containing vendor dependencies, compiled assets, checksums and release metadata.

## Core direction

- PHP 8.4–8.5 certified; newer stable PHP branches are enabled only after platform QA.
- Symfony 8.1 stable backend and Vue 3.5 + TypeScript administration.
- MySQL 8.4 LTS / MariaDB 10.11 LTS+ (11.4 recommended) supported baseline; InnoDB, utf8mb4 and Unicode NFC normalization.
- Europe + Ukraine regional profile; unrelated country-specific packages are not loaded.
- Protected system modules for critical commerce capabilities; optional extensions are isolated and removable.
- No OCMOD/file patching. Extensions use versioned contracts, events, UI slots, APIs and webhooks.
- Server-rendered storefront with progressive interactive components.
- Fulfillment-driven checkout with no universal address form.
- Google Commerce and AI/agent projections are first-class but never checkout reliability dependencies.
- HTTP/2 baseline and HTTP/3-capable deployment; responsive AVIF/WebP media pipeline.

## Default store

A fresh installation defaults to Ukraine (`UA`), Ukrainian (`uk-UA`), hryvnia (`UAH`) and `Europe/Kyiv`. These are defaults, not hard-coded limitations; additional EU markets, locales and currencies remain available.

## SEO URLs

SEO URLs are a protected system capability. Products, categories, brands, CMS/landing pages and blog articles receive an SEO slug automatically when localized content is created. The slug can be edited later; the previous canonical path is retained as a permanent server-side 301 alias pointing directly to the same canonical route, so repeated edits do not form redirect chains.

Entity slugs use clean lowercase ASCII words separated by hyphens and no `.html` suffix. Entity names may change without silently changing the existing slug; URL regeneration is explicit after initial creation, which protects established SEO signals. System pages use stable English routes for every locale, such as `/cart`, `/checkout`, `/shipping`, `/about-us` and `/privacy-policy`. Visible labels remain localized. See `docs/SEO_URLS.md` and `docs/SYSTEM_URLS.md`.

## Multilanguage

Languages are BCP-47 locales, enabled per store. A store has one default locale and can expose additional locales with optional URL prefixes. Product/category/brand content can differ by store and language. The platform does not hard-code a fixed list of languages.

## Multicurrency

Currencies use ISO-4217 codes and integer minor units. A store enables only the currencies it sells in. Explicit prices in the requested currency have priority; automatic conversion is an optional fallback. Historic orders snapshot their currency and amounts and never change when rates change.

## Google product category

Google taxonomy is intentionally separate from the store category tree. A store category can hold an optional Google Product Category default, and an individual product can override it. If neither is supplied, Google automatic classification is allowed. The store category path remains the merchant-owned `product_type`.

## Migration Center

Migration is a protected system capability.

Initial source adapters:
- read-only OpenCart / ocStore 3.x catalog source;
- Universal Transfer Package v1 (`manifest.json` + streaming NDJSON).

The required workflow is scan -> dry run -> mapping -> validation -> batched import -> media transfer -> reconciliation -> publish. Source systems are never modified. Jobs are resumable/idempotent through persistent source-ID mapping.

The OpenCart adapter currently extracts languages, currencies, manufacturers, categories, products, translations, category relations and image paths. Options/variants, attributes, specials/discounts, SEO URL history and production media transfer are the next adapter layers before declaring OpenCart migration production-complete.

## Database

Schema v32 is the current database schema. Schema v30 adds domain-to-store routing, v31 adds operational returns/RMA, product Q&A and saved carts, and v32 adds localized navigation management. Schema v29 introduced the granular `system.audit.view` permission for the administrator audit trail. Earlier schemas include granular administrator roles/store scopes, commerce UX builders, backup/feed/sitemap operations, promotions/coupons, notifications, search/read projections, localization, pricing, inventory reservations, immutable order snapshots and central SEO routing. Internal joins use BIGINT; public IDs use UUIDv7 in BINARY(16); money uses integer minor units; timestamps use UTC DATETIME(6).

## Runtime

- PHP 8.4–8.5 certified
- Symfony 8.1 stable
- Doctrine DBAL 4.4 + Doctrine Migrations
- MySQL 8.4 LTS or MariaDB 10.11 LTS+ (11.4 LTS recommended) for production installs
- Vue 3.5 / Pinia / Vite 8 / TypeScript 7.0.2
- Redis, workers, external search and object storage remain optional accelerators

Dependencies are managed by Composer/npm. Open libraries should not be copied manually into the codebase.




## Browser installation and deployment

The preferred production flow is one complete release archive -> extract -> point the domain document root to `public/` -> open `/setup.php`. The setup bootstrap checks PHP/extensions and release completeness, writes `.env.local`, verifies/creates the database when permitted, and then runs the canonical Symfony `commerce:install` path in-process. Successful installation locks the setup entrypoint and deletes the one-time administrator-password request file. No files are copied or moved after installation.

A production release must include Composer `vendor/` and any compiled frontend output. Source archives without generated dependencies are intentionally rejected by the browser preflight instead of attempting a partial install. See `docs/INSTALLATION.md` and `docs/DEPLOYMENT.md`. Run `php bin/release-check.php` before publishing a release archive; it refuses to certify a package that is missing the Composer runtime.

## Installation and update safety

After Composer dependencies and the database connection are configured, install with:

`php bin/console commerce:install --store-name="My Store" --admin-name="Administrator" --admin-email="admin@example.com" --admin-password="use-a-long-password" --public-url="https://shop.example.com"`

The installer runs the same runtime/database preflight used by updates, executes Doctrine migrations, then creates the first Ukraine store, UA market, uk-UA/UAH defaults, main inventory location, administrator, consent policy and draft information pages in one seed transaction. `mc_installation` prevents accidental reinstallation. The first administration login is `/admin/login`.

Run `php bin/console commerce:system:check` at any time to re-check PHP/extensions, writable runtime/media locations, database version/connectivity, InnoDB, utf8mb4, strict SQL mode and recommended capacity settings. Core updates remain staged and require signed manifests, SHA-256 verification, a database/files checkpoint, migrations, health/smoke tests and an atomic switch. See `docs/INSTALL_UPDATE_QA.md`.

## First operational catalog slice

After installation an administrator can sign in and create, edit, publish/archive categories and product drafts through server-rendered administration pages. Product creation/update is transactional across the canonical product, translation, default variant, market/store publication, base price, inventory item/stock, categories and central SEO route. A failure rolls the entire write back rather than leaving a half-created product. Product descriptions are sanitized server-side before persistence. Public SEO URLs remain stable when names change and are redirected with 301 only after an explicit slug change.

The administration UI intentionally exposes only the minimum first-slice fields plus the required information-page editor. The previously designed full schema-driven product editor remains the target; this release prioritizes correct persistence and lifecycle before adding the remaining editor panels.


## Storefront and cart runtime

The storefront renders the public home/catalog/category/product pages from the same published database records managed in `/admin`. Product and category paths are resolved through the central SEO registry; historical aliases return direct 301 redirects. The anonymous cart is persistent for seven days through a random HttpOnly SameSite=Lax cookie whose SHA-256 token hash is stored in the database. Quantity validation uses the same fixed-point unit/min/max/step rules as the catalog, and cart mutations are transactional. Inventory is intentionally not reserved while a shopper merely keeps an item in the cart; reservation starts only when the checkout/order vertical is completed.

`/checkout` now reads the real cart and performs lazy city -> pickup-point discovery through the Ukrainian delivery-provider layer with manual fallback when a carrier API is unavailable. Payment and final order creation are deliberately not simulated in this version; they are the next vertical layer. See `docs/STOREFRONT_RUNTIME.md`.

Required information pages are real database content keyed independently from their visible title. They are seeded as drafts, editable under `/admin/content/pages`, and stay `noindex` until explicitly populated and published.

## Units, content and SEO crawl control

Variants can be sold by piece, set, pair, pack, roll, kg, litre, metre, square/cubic metre and other registered units, including fractional steps. Runtime filters and sorting never create permanent SEO routes; only deliberately promoted facet landing pages are indexable. Reciprocal hreflang plus x-default are generated from the same localized canonical route set. See `docs/MEASUREMENT_UNITS.md`, `docs/SEO_INDEXING.md` and `docs/STRUCTURED_DATA.md`.

## Information pages

Core includes only the information pages most stores actually need: About us, Contact, Shipping, Payment, Returns, Warranty, FAQ, Privacy Policy, Cookie Policy and Terms & Conditions. They use localized visible titles but stable English URLs and are grouped automatically in the footer. Legal pages carry required-store-field metadata so incomplete seller information can be detected before publication. See `docs/INFORMATION_PAGES.md`.

## VAT and product creation

Tax is a protected system capability. EU/UA consumer storefronts use the final tax-inclusive price as the primary price; optional VAT breakdown can be displayed under it. B2B markets may show net plus gross simultaneously. Tax rates are effective-dated data and orders snapshot net/tax/gross so later rate changes never rewrite history. See `docs/TAX_VAT.md`.

Product creation is schema-driven rather than a single hard-coded form. The editor schema covers identity, translations, categories/publishing, pricing/VAT, units, variants, inventory, media, specifications, shipping, SEO, Google Commerce, EU safety/compliance, documents and product relations. See `docs/PRODUCT_CREATION.md`.

## EU privacy, consumer rights and compliance

The privacy layer also tracks GDPR access/export/rectification/erasure/restriction/objection requests, retention policies and channel-specific marketing consent. Consent choices can be recorded server-side without retaining a raw IP address.

The storefront uses an EU-strict consent model: only strictly necessary storage is enabled before the shopper chooses optional preferences, analytics or marketing. Google Consent Mode v2 mirrors the recorded choice. Legal texts are versioned and checkout snapshots the applicable policy versions.

EU-facing product compliance stays intentionally small in Core: manufacturer/economic-operator identity, EU responsible person where applicable, localized safety information, CE/Declaration of Conformity where applicable, and generic product documents/certificates. Sector-specific recall, serial/lot, energy-label, DPP, EPR/WEEE/battery and customs workflows belong to optional extensions.

EU price-reduction history is durable so a promotion can show the applicable lowest previous price instead of deriving it from transient logs. Checkout forbids preselected paid extras and uses an explicit payment-obligation action. Accessibility engineering targets EN 301 549 / WCAG 2.2 AA. See `docs/PRIVACY_CONSENT.md`, `docs/CONSUMER_RIGHTS.md`, `docs/EU_COMPLIANCE.md` and `docs/ACCESSIBILITY.md`.

## Asset Performance 3.0.7
Admin CSS is separated from storefront CSS, while Builder/Media Library use dedicated feature assets. Heavy UI code must not inflate the initial request. Production minification, tree-shaking and code splitting happen before release, never during an HTTP request. `npm run assets:audit` enforces Brotli budgets; `npm run build:production` builds, audits and precompresses production assets.




## Commerce UX and recovery 3.1.2

Adds verified backup upload/restore from a local PC, explicit purchase states and double-opt-in back-in-stock notifications, customer inquiry handling, published Product Builder runtime layouts, a configurable Checkout Builder, server-authoritative AJAX cart recalculation, and promotion targeting by customer group.

## Backup, feeds and sitemap 3.1.1

Added Backup Center profiles for database-only, store data and full backups, downloadable server archives, and guarded admin data restore with a pre-restore safety snapshot. Feed Center emits atomic snapshots for Google Merchant, Meta/Facebook, Pinterest, TikTok, Rozetka, Prom.ua, generic CSV and JSON from one canonical product/variant projection. Marketplace taxonomy mapping, `robots.txt`, a sitemap index, and paged store/locale sitemaps are included. Large feeds are generated manually or by CLI/cron; public feed URLs only serve completed snapshots.

## Commerce operations 3.1.0

- Server-side promotion/coupon engine with automatic/coupon triggers, percent/fixed discounts, subtotal thresholds, usage limits, product/category scope, priorities and transactional checkout recalculation.
- Product CSV export and preview/apply import (up to 10,000 rows per run) through the normal ProductWriter/event pipeline.
- Event-safe bulk product publish/draft/archive actions (up to 200 selected products per run).
- Unified Notification Center for Email/Telegram/SMS outbox status; optional HTTPS SMS gateway remains OFF by default and is protected by outbound URL policy.
- Double-opt-in newsletter subscriptions, signed unsubscribe URLs and queued campaigns to confirmed subscribers only.
- Optional OpenAI Responses and Gemini generateContent authoring helpers. API keys live only in environment configuration; AI drafts never bypass normal product save/validation.

## Production-critical hardening 3.0.9

The browser installer now writes the complete runtime environment required by the Symfony container. Disabled Google Commerce and Marketing providers no longer enqueue integration work. Integration jobs use an explicit lease and recover after worker crashes. `commerce:queues:status` detects stalled/dead/background backlog, while `commerce:queues:purge` removes only old successful records and preserves unresolved failures. `php bin/production-critical-check.php` guards these invariants in release QA.

Campaign delivery fan-out is asynchronous. After creating a campaign, run the batch enqueue worker periodically:

`php bin/console commerce:campaigns:enqueue --batch=250 --max-batches=20`

The command only queues confirmed active subscribers into the durable notification outbox; the normal notification worker performs actual email delivery.

Detailed commerce operations notes: `docs/COMMERCE_OPERATIONS_3_1.md`.

Granular administrator RBAC and store-scoped access are documented in `docs/ADMIN_ACCESS.md`.

## Order documents 3.3.3
Invoice, packing slip and credit note are issued as immutable snapshots with per-store/year numbering. Print and PDF are available; customers can access only their own invoice/credit-note documents.

3.5.0 extensions use Extension API 2.0 and Settings Schema v2 only; the pre-release Schema v1 compatibility layer was removed. The optional `bonus/` directory contains examples of all three execution models and three installable themes. The five built-in visual presets now have distinct geometry/density styling. Universal Import Wizard recognizes multilingual columns such as `name[uk-UA]`, and multilingual CSV export emits all enabled store locales in one file.
