# Changelog

Nexora Commerce has a single product line on the `main` branch. Only release-level changes are listed.

## 3.20.2 — 2026-09-30

Schema 64. Installation on shared hosting.

### Fixed
- **Installer refused to run on hosts whose MySQL defaults are MyISAM and an empty `sql_mode`** (typical for shared hosting). Every database connection now sets strict `sql_mode` and InnoDB as the default engine for the session, so the server defaults no longer matter; all tables are created as InnoDB (verified on a server with global MyISAM and empty sql_mode).

## 3.20.1 — 2026-09-30

Schema 64. Packaging fix.

### Fixed
- **Installation failed with "Expected to find class … but it was not found"**: the archive shipped a Composer class map (authoritative, no filesystem lookups) built from an older tree, so about a hundred classes added since were unknown at runtime. The package build now regenerates the class map from the packaged files, and `verify-packaged-release` fails the build if any class under `src/` is missing from it.

## 3.20.0 — 2026-09-30

Schema 64. Translations in the admin, publish policy, catalogue quality, VAT per product class with display modes, undo.

### Added
- **Translations of products and categories in the admin**: a "Translations" page per product/category (status per language, all texts and SEO fields, created together with the address of the new language). The **AI translate** button fills a language from the default one; nothing is saved until the form is submitted. Articles already had per-language editing.
- **Publish policy** (Catalogue → Quality): `off` / `warn` / `block` for publishing products and articles that lack an enabled language. Applies to the manual edit forms; imports and bulk editing are not blocked.
- **Catalogue quality**: dashboard card and page `/admin/catalog/quality` (no image, no description, no price, no category, missing translations, no SEO meta, no stock; completeness per language; filter and list).
- **VAT**: tax class per product, VAT rates page (System → Tax) with add/disable/delete, four storefront display modes (one price; incl. VAT with the VAT amount; net with the gross price; net only). Orders now record VAT per line (net price, rate, class) and the order total tax.
- **Undo** of the last bulk product edit (price, stock, status, name, SKU): "Undo" bar and `Ctrl+Z`, valid for 15 minutes, own action only, single use.

### Fixed
- The admin AI buttons and "select all" helpers were never bundled into the admin runtime and did nothing; they are now part of it.
- Order document: the VAT line is shown after the total ("incl. VAT"), not as an extra charge before it.

## 3.19.0 — 2026-09-30

Schema 63. Recommended block in categories, forms in pages, HEIC, legal pages for more countries, quality monitor with history, admin productivity.

### Added
- **"Recommended" block in category listings** (best-selling products of the last 180 days in the category) and a "Popular" sort option.
- **Forms inside pages**: a `[form:address]` shortcode in pages, blog articles and category descriptions embeds a form. New **file** field (type/size checked, stored outside the web root, downloadable only by administrators, removed by the retention sweep) and **per-language labels/help/button texts** for every field.
- **HEIC/HEIF uploads** (iPhone photos) are decoded to JPEG in-process by the PHP Imagick extension (built with libheif; the platform never starts external programs) and converted to WebP on upload. Without HEIC support the upload is refused with a clear message and the quality monitor warns.
- **Legal pages and demo data for other countries**: privacy, terms, returns and cookie drafts in uk, en, ru, pl, de, da with country-specific frames (law, authority, withdrawal period); `commerce:install --country=DE|PL|DK…` seeds pages, demo prices and rates for that country.
- **Quality monitor**: score history (chart), one-click fixes for fixable checks, notification e-mail when the score drops, scheduled task `quality`, console command `commerce:quality:check`.
- **First steps wizard** (Admin → Overview → first steps): products, delivery, payment, taxes, legal pages, two-factor, first order; progress is derived from real data and shown on the dashboard.
- **Admin productivity**: bulk actions in the orders list, Ctrl+K palette also finds products, orders and customers, keyboard shortcuts (`/`, `g d`, `g o`, `g p`, `g c`, `g q`).
- CI: weekly "PHP next" workflow (PHP 8.5 and nightly = future 8.6), non-blocking.

### Fixed
- E2E extension fixture no longer pins the core version (`>=3.0.0`), so version bumps do not break the suite.
- ECB and NBU rate sources verified against the live services.
- Installer with a non-Ukrainian country: the post-install diagnostics no longer demand the Ukrainian locale and UAH, and the store keeps the chosen country (`default_country`, used by the Google Merchant feed label) instead of `UA`.
- Admin form editor: Twig loop syntax compatible with Twig 3; two save buttons (top and bottom) on long forms.

## 3.18.1 — 2026-09-30

- PHP: the upper bound `<8.6` is lifted (`>=8.4 <9.0` in `composer.json`, `PlatformVersion`, installer, `release.json`, manifest). All locked vendor packages already accept PHP 8.6. There is no released 8.6 build yet, so the platform was verified on 8.4.21 (full suite) and on PHP 8.5.8 (unit suite with `E_ALL`, lint of all PHP files); 8.6 itself is covered by the static readiness scan only.
- Fixed a PHP 8.5 deprecation: `openssl_pkey_derive()` no longer receives the deprecated `$key_length` argument (Web Push ECDH).
- "Similar products" no longer pads the list with unrelated products: automatic mode now returns only products that share a category, brand or attribute values.
- E2E extension fixture follows the release version.

## 3.18.0 — 2026-09-30

Schema 62, production channel. International store setup, form builder, quality monitor, collapsible colour admin.

### Added
- **Country-neutral setup.** The installer (browser and `commerce:install --country/--currency/--locale/--timezone`) now asks for the store country and proposes currency, language, time zone and standard tax rate from it; every value stays editable. The whole currency list (40 currencies) is registered so the merchant only switches currencies on; languages and currencies that are missing can still be added by hand. Nothing is seeded as Ukrainian any more: market, tax rate, legal page drafts (English unless the store language is Ukrainian or Russian) follow the chosen country. `STORE_DEFAULT_*` values written by the installer were previously ignored by the seeder; they are now honoured.
- **ECB exchange rates** next to NBU: automatic rates are fetched from the European Central Bank first and from NBU for pairs the ECB does not list; a source that is down is skipped.
- **Form builder** (Admin → Content → Forms): forms with text, e-mail, phone, multi-line, number, date, list, radio and checkbox fields, per-language forms, public page `/forms/{address}`, spam guard, captcha (new "Custom forms" switch), e-mail notification, answers list with new / read / handled status, CSV export (formula-injection safe) and automatic removal of handled answers after a year.
- **Quality monitor** (Admin → System → Quality monitor): a 0–100 score and 26 read-only checks across security, reliability, performance, content and SEO, languages and currencies, and sales setup, each with a hint and a link to the page that fixes it.
- **Collapsible admin sidebar**: one button folds the menu to icons (with tooltips); the state is remembered per browser.
- **Colour tones**: admin navigation icons are tinted per area (catalog / sales / marketing / content / system), dashboard cards get a coloured accent, and the quality monitor uses status colours. New violet and teal tokens (light and dark).
- **Recently viewed** products now also appear on the catalog and cart pages (they already existed on the home and product pages; consent-gated as before).

### Fixed
- Ukrainian and Russian gaps in translations: 63 extension-runtime messages that were English in the Ukrainian catalog, English fallbacks in checkout and catalog texts for ru / pl / de / da, "Credit note" and SEO field labels.

## 3.17.0 — 2026-09-30

Schema 61, production channel. Google sign-in, LiqPay and WayForPay, order fraud scoring, new-device confirmation, product info blocks, article ↔ product links.

### Added
- **Google sign-in** on the customer login page (authorization-code flow, state + nonce, ID-token claims checked). An existing account is linked only when its e-mail was already verified; otherwise a new verified customer is created. Configured through `GOOGLE_LOGIN_*` in `.env`; hidden until enabled.
- **LiqPay** and **WayForPay** payment providers (hosted checkout, signed callbacks, refunds / cancel where the API allows, replay guard, amount and currency check). Configured through `LIQPAY_*` / `WAYFORPAY_*` in `.env`.
- **Order fraud scoring** (Admin → System → Order fraud): advisory score with reason codes (IP and e-mail velocity, failed payments, one phone many e-mails, disposable e-mail, high value, guest COD), a blocklist (e-mail, domain, phone, IP), review / clear decisions and a `fraud.flagged` order event. Off by default per store.
- **New-device confirmation** for customers: a 6-digit code sent by e-mail when signing in from an unknown browser; trusted for 180 days. Off by default per store; fails open on errors.
- **Product info blocks** (size tables, lists, rich text, per locale) edited on the product form, shown on the product page.
- **Article ↔ product links**: attach products to a blog article by SKU; the article lists them and the product page lists its articles.
- `tools/check-php86-readiness.php`: static scan for PHP 8.6 incompatibilities (0 findings). PHP 8.6 stays outside the supported range until the platform is certified on it.

### Fixed
- Content-Security-Policy `form-action` now allows the hosted payment page hosts, otherwise browsers block the checkout redirect to an external payment provider.
- The payment webhook URL is built from the provider code instead of being fixed to Monobank.

## 3.16.0 — 2026-09-30

Schema 60, production channel. AI assistant, first-party visit analytics, data retention.

### Added
- **AI assistant** rebuilt (Admin → System → AI assistant): providers OpenAI, Gemini and Claude with keys stored encrypted (never shown again; `.env` values remain as a fallback), model per provider, key test, a daily request limit per store and a usage log. Tasks: product description, category texts, SEO title / description, translation and a reply to a customer. Helpers appear on the category, blog and page forms and only when a provider is active. Results are drafts: nothing is saved or published without a person. Customer text is passed as quoted data, the answer must be one JSON object, lengths are capped and script-like tags are stripped.
- **Visit analytics** (Admin → Analytics → Traffic): sessions, visitors, page views, pages per session, bounce rate, sources (search / social / referral / UTM / ad click ids), devices, landing pages, top pages and a visit funnel (session → product → cart → checkout → order). Cookie-free: a visitor is a daily rotating keyed hash, IP and User-Agent are never stored, bots, prefetch, the admin area, account pages and non-page responses are not counted, and recording happens after the response is sent. No per-page-view rows exist: page views are counters per day and path (capped at 3000 paths per day). The dashboard funnel links to it.
- **Data and storage** page (Admin → System): the largest tables, what the cleanup would remove right now and a run button.

### Changed
- Retention now also removes closed customer inquiries (365 days, they hold contact data), AI and automation logs (90 days), push subscriptions without a successful delivery for 270 days, marketing automation deliveries and extension events (365 days), closed price history rows (730 days) and raw visit sessions / daily page counters (per store, default 400 days).
- The product form no longer offers a provider that is not configured; the old `/admin/api/ai/product-draft` endpoint is kept and now goes through the same limits and log.

## 3.15.0 — 2026-09-30

Schema 59, production channel. New dashboard, automation, web push, downloads, custom fields, manual orders.

### Added
- **Dashboard** rebuilt around what needs action: KPI cards with change against the previous period (7 / 30 / 90 days), a revenue chart with your own notes, month goals with a projection, an order pipeline, a sales funnel, attention items grouped into orders / customers / catalog / system, top products, restock hints (stock cover under 14 days), order sources, searches without results, recent orders and customer inquiries.
- **Manual orders** (Admin → Orders → Manual order): phone / chat orders through the normal checkout pipeline (stock, taxes, numbering, events); a quick-order inquiry converts into an order in one click.
- **Automation rules** (Admin → Marketing → Automation): "when → then" for order placed / completed / cancelled, new customer, inquiry, review, return; actions are email, Telegram, push to admins and a webhook (public https only). Each event fires once per rule.
- **Web push** (Admin → System → Push): own VAPID + RFC 8291 implementation without extra libraries, verified against the RFC test vector; storefront subscribe toggle, admin devices, test and broadcast.
- **Downloads centre** (`/downloads`, Admin → Content → Downloads): catalogues, manuals and price lists next to product documents; type whitelist by real content, content-addressed storage, download counter.
- **Category texts**: an introduction above and an SEO text below the product grid, sanitised on save and on output.
- **Custom product fields** (metafields): text / number / link / date, optional display on the product page.
- **Customer login captcha** and a **captcha outage mode**: Google / Turnstile on a network failure or a 5xx reply either let the form through (default) or block it; the rate limit and honeypot stay active in both modes. Admin login is deliberately excluded (it already has throttling and MFA, and a captcha outage must not lock out the operator).
- **Promotions**: a market condition (matched by the currency of the cart market) and a clear note on how priority and "stop processing" combine discounts.

### Changed
- `CaptchaVerifier` gained `required()`; `CheckoutOrderService::place()` accepts trusted per-line price overrides (used only by manual orders).
- The icons gate now allows inline `<svg data-chart>` for data charts; every other inline svg is still rejected.

### Notes
- A quick order is an inquiry, not a cart: it is not counted as an abandoned cart. The dashboard funnel shows it on its own line.

## 3.14.0 — 2026-09-30

Schema 58, production channel. Captcha, product display variants, analytics tags, conversion tools.

### Added
- **Captcha** (Admin → System → Captcha): built-in image captcha (self-hosted, stateless signed token, single use, arithmetic fallback without GD), Google reCAPTCHA v2 / v3 and Cloudflare Turnstile. The forms that require it are chosen individually: registration, password recovery, contact, call-back, price request, quick order, newsletter, back-in-stock, review, question, forum, contract withdrawal. Secret keys are stored encrypted; the legacy `TURNSTILE_*` variables still work until settings are saved.
- **Product display variants** (Admin → Appearance → Storefront): card style (classic, minimal, outlined, text-over-photo, list), 3/4/5 columns, photo ratio, always-visible or on-hover buttons, home category tile style, four product page layouts.
- **Analytics & pixels** (Admin → System → Analytics & pixels): GA4, Google Tag Manager and Meta Pixel by validated ID. Tags load only after the matching consent category; `view_item` / `add_to_cart` events are emitted (purchases stay server-side).
- **Quick order** (one-click order request from the product page, stored as an inquiry), **sale countdown** for prices with an end date, **live total** for quantities above one, **sticky header**, **back-to-top** button and **benefit lines** under the buy button — each switchable.

### Fixed
- The contact / call-back / price-request endpoint was released in 3.13.1 without the anti-spam gate because of a failed patch; it is now covered by a test.
- Consent activation of inline tags now keeps the CSP nonce, so consent-gated inline snippets run under the strict CSP.

### Changed
- `TurnstileVerifier` was replaced by the provider-neutral `CaptchaService`; registration and withdrawal use it.
- Chat and captcha/analytics hosts are merged into the page CSP through one helper instead of overwriting each other.

## 3.13.1 — 2026-09-30

Schema 57, production channel. Cookie consent and anti-spam audit fixes.

### Security
- Anonymous storefront forms — contact, "Request a call", price request, newsletter, back-in-stock — were protected by CSRF only. They now share one gate (`PublicFormProtection`): hidden honeypot, render-time check and a per-client rate limit (8 requests/minute per form type).

### Privacy
- The cookie banner links to the Cookie policy and the Privacy policy.
- "Recently viewed" now stores data on the device only after "Preferences" consent, and removes it when consent is withdrawn.
- The reopened cookie settings panel closes on Escape (the first-visit banner still requires a choice).

### Tests
- New e2e `consent-and-spam.spec.ts` (before choice / reject / granular / accept / withdraw, policy links, honeypot, timestamp) and unit `PublicFormProtectionTest`.

## 3.13.0 — 2026-09-30

Schema 57, production channel.

### Added
- **Live chat setting** (Admin → Appearance → Contact buttons): Tawk.to, Jivo, Crisp or Chatwoot. Paste the vendor's embed code (or just the ID); only the vetted loader of the chosen service runs, never arbitrary scripts.
- The chat loads only after the visitor allows "Preferences" cookies; the page Content-Security-Policy is extended with the chosen service's domains only. With contact buttons on, a "Live chat" entry opens the chat (or the cookie settings before consent).

## 3.12.0 — 2026-09-30

Schema 56, production channel.

### Added
- **Floating contact buttons** (Admin → Appearance): request a call (stored as an inquiry), write, call, Viber, Messenger, Telegram; position left/right, dark-theme safe, lifts above the cookie banner and the mobile buy bar.
- **Delivery regions in checkout**: the region list from Admin → Shipping is now a required checkout field; the server enforces it and saves the region with the shipment destination.
- **IndexNow** (Admin → System): key file, automatic 30-minute cron submission of changed URLs, "submit all" action, `commerce:indexnow:submit`.
- **Share links on product pages** and *Copy link* for products and articles (shared component).
- **E-mail templates** (Admin → Notifications): editable subject and text per language for order received, order status, inquiry received and subscription confirmation, with placeholders and restore-to-default.
- Contrast audit now also covers detail/edit pages of every admin section, forum and account pages, the open contact widget and its dialog, in both colour schemes.

### Changed
- Sitemap visibility rule is shared with IndexNow (`SitemapController::VISIBLE_ARTICLE`).

## 3.11.1 — 2026-09-29

Schema 55, production channel.

### Added
- **Demo blog showcase**: six long-form articles (what Nexora is, advantages, built-in SEO, storefront management without a developer, security, launch checklist) in uk/ru/en with covers, three categories, tags, author, reading time and a featured article. Installed with `--demo`, removed with the demo data.

## 3.11.0 — 2026-09-29

Schema 55, production channel.

### Added
- **Blog** (Admin → Content → Blog): article editor with the rich text editor and HTML mode, per-language translations, categories, tags, cover image with alt text, author, reading time, featured article, scheduled publishing, per-article canonical/noindex, live Google snippet preview with length counters, sanitised HTML.
- **Blog storefront**: featured hero card, category pages, tag archives (noindex), search, pagination, article table of contents with heading anchors, related articles, previous/next, share links, RSS feed (`/blog/feed.xml`) with autodiscovery.
- **Blog SEO**: BlogPosting + BreadcrumbList JSON-LD (author, image, keywords, wordCount, section), Blog collection JSON-LD, hreflang alternates for every published translation, canonical override, Open Graph URL/site name and Twitter card on all pages, categories in the sitemap.

### Fixed
- Sitemap advertised draft and scheduled articles; only published, indexable articles are listed now.
- **Admin sign-in hardening**: sessions are stored in `var/sessions` (shared hosts with an unwritable system session path made every sign-in bounce back to the form), `/admin`, `/account`, `/checkout`, `/cart` and the installer are sent with `Cache-Control: no-store` (a cached sign-in form carries a stale CSRF token), and the sign-in page now distinguishes too many attempts / expired session from wrong credentials.

## 3.10.1 — 2026-09-29

Schema 54, no database changes.

### Fixed
- **Production archive contained build-machine data**: `var/install/installed.lock`, test extensions and caches were packaged, so the installer could report "already installed". The packager now ships only empty `var/` skeleton directories and fails if anything else (or `installed.lock`) is present.
- `var/.gitkeep` is tracked, so a fresh clone of `main` has the `var/` directory.

## 3.10.0 — 2026-09-30

Schema 54, Extension API 2.0, production channel.

### Added
- **Product badges** (Admin → Catalog → Product badges): automatic "Sale", "New" and "Bestseller" rules (period and sales threshold configurable), custom badges assigned by SKU, colour tones, per-locale text, priority; up to three badges per card and on the product page.
- **Delivery countries and regions** (Admin → Shipments → Delivery countries): enable only the countries you deliver to (checkout is blocked for others once a list is saved) and enable/disable/add regions per country with one-click default regions for UA, PL and DK.
- **Rich text editor**: HTML source mode, strikethrough, H3, quote, horizontal rule, tables, image by URL, clear formatting.
- **Admin dashboard alerts**: cron not running, failed cron tasks, new returns, new withdrawal notices (with "processed" action), items awaiting moderation.
- Product documents show their type (certificate, manual, SDS, datasheet, warranty).
- Gates: `bin/admin-confirm-check.php` (every destructive admin form must ask for confirmation); `bin/license-audit.php` now audits only redistributed dependencies and accepts permissive licences (MIT-0, CC0, BlueOak, Python-2.0).

### Fixed
- Ten destructive admin actions (delete synonyms/boosts/relations/saved views/menu items/snippets, layout rollback, forum bans) had no confirmation dialog.

## 3.9.0 — 2026-09-30

Schema 53, Extension API 2.0, production channel.

### Added
- **EU withdrawal function** (Directive (EU) 2023/2673): public `/withdrawal` page linked from every footer, two steps ("Withdraw from contract here" → "Confirm withdrawal here"), acknowledgement email with reference and date/time, notices listed in Admin → Customer experience. Works for guests; the notice is stored even when the order is not matched. Migration adds `mc_withdrawal_notice`.
- **Accessibility statement** (`/accessibility`, European Accessibility Act) in six locales, linked from the footer.
- **Mandatory administrator 2FA policy**: `ADMIN_REQUIRE_MFA=1` forces enrolment before any admin page opens and forbids disabling 2FA.
- **PHPStan level 3** in CI (`composer analyse`, pinned phar, no baseline).
- Automatic text colour on brand-coloured surfaces (`contrast_color`) so custom brand colours keep WCAG AA contrast.
- Playwright: axe colour-contrast scan of storefront and admin in light and dark schemes, bonus themes/extensions, withdrawal flow and 2FA policy.

### Fixed
- Colour contrast in light and dark schemes: filled primary/accent buttons and badges, danger badge, warning text, admin navigation labels.
- Product Trust Badge bonus extension (1.0.1): manifest block and empty slot rendering on the product page.
- Phpstan findings: missing `MediaImageService::writeJpeg()`, delivery point cache key, public API catch of non-exception errors.

## 3.8.1 — 2026-09-29

Schema 52, Extension API 2.0, production channel.

### Added
- **Module uninstall** (Admin → System → Extensions): removes a non-active version with its files, published assets and settings; optional "with module data" runs the package's `down` migrations. Recorded in the lifecycle audit trail.
- `commerce:extension:keygen` creates an Ed25519 publisher key pair and prints the manifest and `trusted-publishers.json` fragments.
- `public/.htaccess`: Brotli/Gzip precompressed asset delivery, on-the-fly compression for HTML/JSON/XML and cache headers (immutable for hashed build assets) for Apache hosting, matching the nginx example.
- Playwright `extension-authoring` covers the whole module life cycle for declarative and signed PHP modules.

### Fixed
- Rolling an extension back to its previous version always failed because the rollback called activation, which forbids downgrades. Rollback now activates the older version explicitly.
- The `product-block` scaffold and the docs example declared regions `main`/`sidebar` that the product page does not render, so the block was accepted but never shown. Scaffold and example use real regions and the validator rejects unknown product regions.
- `commerce:extension:scaffold` generated a broken `Entrypoint.php` for trusted modules (`TrustedExtensionContext \)` plus a PHP warning); the `trusted-route` preset now generates a working route and handler.
- `commerce:extension:pack` failed on any trusted module because the placeholder signature was validated before signing; pack now validates structure and reminds to sign.

## 3.8.0 — 2026-09-29

Schema 52, Extension API 2.0, production channel.

### Added
- **Two-factor authentication for administrators** (TOTP, RFC 6238): enrolment with secret + `otpauth://` URI, 8 single-use recovery codes, replay protection (a time step can be used once), lock-out of the session after 5 wrong codes, secrets encrypted with `SecretVault`. Managed at Admin → Account security; migration `Version20260929120000` adds `mc_admin_mfa`.
- **Dark theme**: `theme.color_scheme` (light / auto / dark) in Admin → Appearance. Auto follows the device and shows a visitor toggle remembered in the browser; the admin panel has its own toggle. All colours come from design tokens.
- **PWA**: web app manifest, service worker (static assets cached, HTML never cached, private areas bypassed) and an `/offline` page; can be switched off in Appearance (a self-unregistering worker is served then).
- **Recently viewed** block on the home and product pages (stored in the visitor's browser only).
- **Error monitoring hook**: optional `ERROR_WEBHOOK_URL` (https) receives a minimal JSON payload for every intercepted runtime incident; no stack traces or personal data.
- Playwright coverage: `admin-mfa`, `color-scheme`, `pwa`, `recently-viewed`; PHPUnit: `TotpTest`, `IncidentWebhookNotifierTest`.

### Changed
- Design tokens gained `--mc-color-primary-base` / `--mc-color-accent-base` (brand colours) with derived hover colours and dark-mode overrides.
- Lucide set extended to 140 icons.

## 3.7.2 — 2026-09-29

Schema 51, Extension API 2.0, production channel.

### Fixed
- `ConfigurationRevisionStore::save()` referenced `$payload` inside its transaction closure without importing it, raising `Undefined variable $payload` on every settings save (store, site, appearance) and defeating the "identical configuration → no new revision" check. Saving unchanged settings no longer creates a revision, and the warning is gone from the runtime log audit.
- The UI-text Twig functions no longer treat a template loop variable named `locale` (as on Admin → Localization) as the UI locale; this removed hundreds of `Array to string conversion` warnings per page render.
- CI runtime-log audit ignores the expected `AccessDeniedHttpException` produced by the read-only RBAC E2E check, in addition to 404s.

## 3.7.1 — 2026-09-29

Schema 51, Extension API 2.0, production channel.

### Added
- Complete, ready-to-review texts for all ten system information pages (about, contacts, delivery, payment, returns, warranty, FAQ, privacy policy, cookie policy, terms) in Ukrainian and English (`resources/content/information/`). The installer creates them as drafts filled from the store profile; missing legal details show as highlighted fields. The demo publishes them.
- Cookie policy lists the cookies the storefront really sets (`PHPSESSID`, `mc_cart`, `store_locale`, `store_currency`, `store_market`, `mc_consent_v1`) with purpose, duration and consent category.
- Curated technical specifications (display, processor, camera, battery, connectivity, OS, ports, weight, features) for 26 of the 29 demo products, shown in the product summary and the full specification table.
- Long-form page typography: tables, ordered lists, links, code and placeholder highlighting.
- Playwright: `information-pages.spec.ts` checks every information page, the cookie table and reopening consent from the footer, and product specifications.

## 3.7.0 — 2026-09-29

Schema 51, Extension API 2.0, production channel.

### Added
- One design-token layer (`assets/shared/tokens.css`) for admin and storefront: spacing, type scale, control heights (32/40/48), radius, elevation, z-index layers, icon sizes and a fixed breakpoint set. Density in Appearance now really rescales controls and spacing.
- Lucide is the only icon set: icons are generated from the installed `@lucide/vue` package (`tools/build-lucide-icons.mjs` → `resources/icons/lucide.json`) and rendered by `ui_icon()` / `lucideIcon()`.
- Release gates: `icons-check` (only real Lucide icons, no inline SVG), `css-coverage-check` (every template class has a style), `design-tokens-check` (no raw font sizes, z-indexes, radii, shadows or breakpoints).
- Demo catalog grows to 29 products in 5 categories (DummyJSON, MIT); demo counts on the home page are computed from real data.

### Changed
- Admin and storefront CSS rewritten from scratch on the tokens (one button, form, table, card, badge, modal and notice system). Admin gets a grouped sidebar with icons, breadcrumbs, a working dashboard action center, icon row actions and consistent list toolbars.
- Storefront: compact non-blocking cookie card, rebuilt hero, product-on-tone promo banners, uniform product cards, adaptive category tiles, text-first article cards.
- Storefront font is chosen in Appearance: System UI, Inter Variable or Manrope (self-hosted).
- New stores ship without promotional artwork; hero and banners appear after they are configured.
- Vite `base` is `/build/`, so chunk preloads no longer 404.

### Fixed
- Home page tiles and banners linked to categories that did not exist (`/kids`, `/beauty-health`, …); they are now built from real categories.
- Forum search returned 500 (invalid `ESCAPE` clause).
- Product page showed the raw unit code (`item`) next to the quantity.
- About 70 admin and 55 storefront CSS classes used in templates had no styles; Bootstrap-style leftovers removed.

## 3.6.6 — 2026-09-29

Schema 51, Extension API 2.0, production channel.

### Added
- Store-scoped customer membership and customer groups; customers are bound to the store on registration, cart binding and checkout.
- Store scope carried through the integration, Google and marketing job queues, delivery records and diagnostics/retry.
- Real-runtime E2E: admin catalog CRUD against the storefront, category merchandising, Media Library lifecycle, read-only RBAC with a limited admin, cron execution state, route sweep of every admin/storefront route.
- CI workflows: quality gate, QA, database runtime matrix (MySQL 8.4, MariaDB 10.11 / 11.4), production certification, release package build.
- Self-hosted Inter and Manrope fonts (no third-party font requests; compatible with the CSP).

### Changed
- Site capability guard is a single storefront subscriber; the admin runtime uses server-defined capability profiles.
- Release metadata, version and schema are derived from the platform version and validated by `release-contract-check`.
- `main` is the only canonical branch; `vendor/`, compiled assets and `SHA256SUMS.txt` are build artifacts and are no longer stored in git.
- Documentation consolidated: versioned capability matrices, QA notes and README sections replaced by one current copy.

### Fixed
- Installer and UI showed raw translation keys: 45 missing uk-UA/en-US keys added (installer errors and success screen, SEO descriptions, scheduler labels, checkout price error).
- Hardcoded exception literals and template strings ("OK", "Email") moved to translation catalogs.
- Schema 51 store-scope migration made atomic; media table collation pinned across MySQL and MariaDB.
- Read-only RBAC gaps for Universal Import, multilingual export, SEO redirect manager and Nova Poshta actions.
- Stale release-gate checks (demo showcase, multistore isolation, install/upgrade safety, localization) aligned with the current implementation.
- SQL accidentally extracted into a translation catalog removed, with a regression gate; DOM-sink XSS hardening in storefront updates and appearance presets.
- Unsafe defaults: reverse-proxy trust is explicit (`SYMFONY_TRUSTED_PROXIES`); fresh uninstalled storefront redirects to `/setup.php`.

## 3.5 — 3.4 lines

Extension API 2.0 with signed trusted releases, reversible-only extension migrations, rollback and quarantine; Theme SDK and bonus themes; universal CSV/XLSX import wizard with multilingual columns; SEO redirect manager; brand assets and per-store image-processing policy; core update preflight against active extensions; developer CLI (validate, test, pack).

## 3.3 — 3.0 lines

B2B accounts and price lists; gift cards and loyalty; Nova Poshta shipment lifecycle; invoices and credit notes; admin RBAC with saved views, previews and audit log; Visual Store Editor with presets and theme tokens; Backup/Restore and Feed Center; promotion/coupon engine, notification center, newsletter double opt-in, optional AI authoring; asset performance budgets; immutable `releases/current/shared` update executor.

## 2.x — 0.9

Foundation: catalog, cart, checkout, orders, payments (monobank, bank transfer, COD), shipping providers, SEO router and sitemaps, media engine, queue and scheduler, installer, Migration Center for OpenCart/ocStore, Google Commerce and marketing integrations.
