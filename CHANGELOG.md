## 3.5.0 — 2026-09-26

- Full-audit hardening: fixed missing RBAC mappings for Universal Import, multilingual export, SEO redirect manager and Nova Poshta shipment actions; `admin-access-check.php` now audits every discovered Symfony admin route against the real permission resolver.
- Trusted extension migrations are now reversible-only (`up` + `down`). Failed activation compensates newly applied migrations, downgrade is forced through rollback, and rollback reverses the newer version delta before activating the previous version.
- Reverse-proxy trust is no longer `REMOTE_ADDR` by default. `SYMFONY_TRUSTED_PROXIES` is explicit and empty on fresh installs unless the operator configures known proxy IPs/CIDRs.
- Fresh, uninstalled storefront requests now redirect to `/setup.php` instead of falling into the emergency 500 page.
- Release QA hardened: PHPUnit/tests are mandatory in source release builds; RBAC, analytics, dependency-license and catalog-scale gates are mandatory; route counting now parses Symfony `#[Route]` attributes instead of broad `name:` grep.
- Added strict `tools/certify-production.sh`: certified production requires an isolated real database, live HTTP smoke, k6 load test and Pa11y WCAG2AA browser checks. Added MySQL 8.4 and MariaDB 11.4 runtime CI definitions for source repositories.
- Dependency license normalization accepts the Composer legacy `LGPL-2.1` identifier used by Dompdf; license audit now reports zero violations.
- Removed pre-release Modern Commerce technical identifiers from locks, secret-vault salt, webhook headers/User-Agent, transfer package format, privacy export format, cache prefix and worker naming.
- Admin preview metadata updated to Nexora Commerce 3.5.0 / Extension API 2.0 / schema 45.
- Release channel changed to `release-candidate`; production certification is intentionally blocked until live DB/browser/load/external-integration gates pass.
- Виправлено три підтверджені runtime-помилки Twig у Media Library, Integrations/Google Merchant diagnostics та Universal Import Wizard.
- Пагінація Media Library знову використовує чистий Twig-вираз без випадкового i18n-втручання в оператор порівняння.
- Google Merchant diagnostics коректно обрізає issues_json через slice(0, 120) і більше не містить вкладеного {{ }} у виразі.
- Universal Import Wizard переведено зі старого Twig `for ... if ...` на Twig 3-сумісні вкладені умови.
- Додано `bin/twig-syntax-check.php`: усі Twig-шаблони парсяться реальним Twig lexer/parser без залежності від БД. Production build/package тепер обов’язково запускає цей gate після i18n audit.
- Публічні package metadata синхронізовано з брендом Nexora Commerce (`nexora-commerce/platform`, `nexora-commerce-ui`).


- SEO URL lifecycle hardening: admin redirect history, hit analytics, safe redirect deletion, brand canonical synchronization and multilingual OpenCart/ocStore SEO URL preservation.

- Extension API 2.0 only; removed pre-release Settings Schema v1 compatibility.
- Commercial extension metadata for free, one-time, subscription and external licensing models.
- Multilingual product CSV export/import with locale columns such as `name[uk-UA]` and `description[en-US]`.
- Distinct storefront presets plus optional bonus themes.
- Bonus Extension API examples for declarative, remote_app and signed trusted_release models.
- Module manager version visibility, lifecycle history and compatibility safeguards retained and hardened.

## 3.4.6 — 2026-09-26
- Added signed Theme SDK storefront Twig overrides for trusted themes, while blocking admin/email/order-document template overrides.
- Added universal CSV/XLSX Catalog Import Wizard with column mapping, autodetection and reusable per-store profiles; OpenCart-like export headings are recognized automatically.
- Added extension lifecycle audit log for install, activate, update, disable, rollback, quarantine/runtime isolation and failures.
- Core update preflight now validates every active extension against the target Core version and Extension API before switching releases.
- Trusted extension bootstrap failures now isolate only the failing extension and record the incident instead of taking down Core.
- Extension version upgrades remain immutable and atomic: install a new version beside the old one, activate it, and retain the previous version for rollback.
- Fixed Stability-center Twig status expressions discovered during module/update QA.
- Extension SDK hardening: settings schema v2 is now canonical and backward-compatible with v1; scaffold presets cover basic, product-block, remote-integration and trusted-route modules.
- Added extension developer CLI: validate, test, pack, list-points, list-events and list-contracts.
- Trusted extensions can register declared payment, shipping, product-block and AI providers through public contracts without patching the Symfony container or Core.
- Trusted runtime now enforces manifest-declared routes, events, capabilities, block schemas and UI-slot contributions.
- Expanded stable storefront/admin extension points and added safe generic slot renderers.
- Added full Developer Guide, Ukrainian quick guide, provider-contract documentation and reference extension packages under docs/examples/extensions.
- Module Manager now exposes publisher, execution model and declared capabilities for clearer install decisions.
- Fixed malformed refund availability condition in the admin order view found during extension-slot QA.

- Added store-brand personalization for logo, compact icon and favicon through Media Library selection; storefront header/footer now use the configured brand assets and the legacy “M” mark was removed.
- Hero and promo image fields now use Media Library picker UI instead of manual media-path entry.
- Image processing is now store-configurable: preserve uploaded format or explicitly choose JPEG, PNG, WebP or AVIF, choose generated widths and whether to keep source-size output; WebP/AVIF are no longer generated automatically.
- Product, review, imported and Media Library image uploads now follow the same per-store image-processing policy while retaining hash-based ASCII storage keys.
- Extension API upgraded to 1.1 with universal manifest contributions for blocks, routes, pages, stable UI slots, permissions and scoped assets.
- Added dynamic extension Builder components; missing/disabled extension blocks no longer invalidate saved layouts and are safely skipped at runtime.
- Added signed Trusted Module Package flow using Ed25519 publisher keys; unsigned PHP/JS remains quarantined and shell/native payloads remain forbidden.
- Added trusted runtime entrypoint, route/event handler bridge, extension-owned migration ledger and versioned asset publishing.
- Added extension RBAC permission registration and restricted trusted admin routes under `/admin/extensions/<module>/...`.
- Expanded stable product/checkout/admin UI slots and lifecycle event catalog.
- Database schema advanced to 44 for `mc_extension_migration`; release gates now use one `PlatformVersion::DATABASE_SCHEMA` source instead of hardcoded schema 43.
- Added `commerce:extension:sign` and extended scaffold support for trusted module skeletons.

## 3.4.5 — 2026-09-25

- Core localization migrated from mixed flat files to locale-first directories: `resources/translations/<locale>/<domain>.php`.
- Canonical `uk-UA` catalog split into `admin`, `runtime`, `api`, `commerce`, `core`, `storefront`, `installer`, and `emergency` domains without changing translation keys.
- TranslationCatalogLoader now recursively merges domain files and retains read-only compatibility for legacy flat Core catalogs.
- Extension localization keeps JSON isolation but the scaffold now creates locale folders: `translations/uk-UA/messages.json` and `translations/en-US/messages.json`; nested domain JSON catalogs are supported recursively.
- Localization/release checks updated to reject legacy flat Core catalogs in new releases and verify locale-directory structure.

## 3.4.4 — 2026-09-25

- Формалізовано контракт локалізації Extension SDK: `manifest.json.localization`, обов’язковий `uk-UA` fallback, список locale та `translations_path`.
- Declarative/remote extensions використовують безпечні JSON-каталоги замість executable PHP language files.
- Додано обов’язковий namespace `extension.<normalized_extension_code>.*`, перевірку дубльованих/чужих ключів і namespace-collision guard.
- Runtime завантажує локалізацію лише активної версії розширення з `var/extensions/installed/<code>/<version>/...`; Core-каталог не може бути перезаписаний модулем.
- `commerce:extension:scaffold` тепер створює `translations/uk-UA.json` та `translations/en-US.json` і localization contract у manifest.
- Оновлено Extension SDK та policy локалізації без введення OCMOD/Core patching або ослаблення quarantine policy.

## 3.4.3 — 2026-09-25

- Публічний бренд системи змінено на Nexora Commerce без зміни внутрішніх PHP namespace та API-контрактів.
- Browser installer посилено фактичними write/rename/delete probes для робочих каталогів і PHP tmp.
- Додано перевірку Document Root -> public/, OPcache enable, PHP CLI/bin/console, symlink, outbound HTTPS, upload/post/max_execution/max_input_vars.
- Перед міграціями installer фактично перевіряє CRUD/CREATE/ALTER/INDEX/DROP/REFERENCES через ізольовані probe tables та очищає їх у finally.
- Додано перевірки foreign_key_checks і utf8mb4 collation.
- Виправлено server-side validation профілю forum.
- Після встановлення health summary показує PHP, БД, кількість міграцій, Kernel, cron/email/backup next steps.

## 3.4.2 — 2026-09-25

- Исправлен i18n runtime audit: теперь он обнаруживает hardcoded exception literals независимо от языка и не даёт false-green.
- Прямые пользовательские exception-сообщения перенесены в украинский translation catalog; технические коды остаются кодами.
- Forum зарегистрирован в SystemModuleCatalog и включён в формальный Beta maturity/readiness lifecycle.
- Каноническая schema БД сохранена на версии 43 во всех release metadata.
- Матрица production DB унифицирована: MySQL 8.4 LTS / MariaDB 11.4 LTS.
- Уточнена фактическая зрелость shipping-интеграций: полный carrier shipment lifecycle заявляется только для Nova Poshta; Ukrposhta/Meest/Delivery Auto остаются provider/location transport + manual shipment fallback до отдельных gateway.
- Production one-upload package очищен от промежуточного build-input.
- README/production-readiness/capability metadata синхронизированы с текущим состоянием.

# 3.4.2 - 2026-09-25
## 3.4.2 SOURCE CHECKPOINT 16

- Змінено політику локалізації: `uk-UA` є єдиним канонічним каталогом для всіх нових UI/runtime текстів; інші локалі тимчасово не розширюються і використовують український fallback для відсутніх ключів.
- Додано `resources/translations/ui.uk-UA.php` як український каталог для admin, runtime JavaScript/Vue, CLI та server-side повідомлень, а також dependency-free `emergency.uk-UA.php` для аварійного bootstrap-відгуку.
- Винесено з runtime-коду українські тексти в PHP, Twig, JavaScript, TypeScript/Vue та bootstrap. Єдиний дозволений кириличний виняток у `src` — таблиця символів `UkrainianTransliterator`, яка є алгоритмічними даними, а не UI-текстом.
- Адміністративні Twig-шаблони, Vue-компоненти, runtime JS, installer та emergency-page переведено на мовні ключі. Звичайні англомовні UI-підписи в Twig також винесено в каталог; технічні токени на кшталт SKU/GTIN/API/URL залишаються незмінними.
- Інформаційні сторінки та footer-конфігурація використовують `title_key` замість вбудованих користувацьких назв.
- Додано `bin/localization-contract-check.php`; існуючий i18n gate тепер перевіряє канонічний `uk-UA`, наявність усіх використаних ключів, відсутність hardcoded Cyrillic у runtime та забороняє створення нових `ui.<locale>.php` для інших мов на цьому етапі.
- Старі `en-US`, `de-DE`, `da-DK`, `ru-RU`, `pl-PL` storefront-каталоги не змінені та залишаються по 480 ключів.
- Оновлено статичні QA-gates, які раніше залежали від буквального тексту, щоб вони перевіряли semantic localization keys і структурні контракти.
- Повторно пройдено повний PHP lint та release/security/commerce/UX/readiness static gates.
- Пакет залишається SOURCE: реальні `composer.lock`, `package-lock.json`, `vendor/` та `public/build/` ще мають бути створені у dependency-enabled build середовищі.

## 3.4.2 SOURCE CHECKPOINT 15

- Completed storefront-template i18n coverage: all 67 non-admin Twig templates are now free of hardcoded Cyrillic UI copy and use the shared translation layer where static copy is required.
- Storefront translation catalogs for uk-UA, en-US, de-DE, da-DK, ru-RU and pl-PL now have exact key parity with 480 keys each.
- Localized home/demo, category, content/contact, forum, cart recovery, order emails and immutable order documents.
- Made `ui_text()` Twig rendering context-aware so queued emails and documents honor the stored order/template locale even when rendered without an HTTP request.
- Localized order-created/order-status email subjects and plain-text/SMS order notifications from the persisted order locale.
- Strengthened `bin/i18n-critical-journey-check.php` to scan every storefront Twig template, verify all referenced keys in every locale, enforce catalog parity and verify locale-safe queued notifications.
- Updated XSS-safe localized templates to avoid `|raw` interpolation for customer/order values.
- Updated multilingual-sensitive regression checks to assert semantic translation keys instead of Ukrainian display text.
- Added `resources/platform/beta-readiness.php` and `bin/beta-readiness-check.php`: all 16 Beta modules now have explicit static evidence and runtime exit criteria. Current classification is 14 static-ready and 2 integration-pending; no Beta module is foundation-incomplete.
- Production packaging remains SOURCE-only until real Composer/npm lock files, `vendor/` and compiled `public/build/` are generated on a dependency-enabled build machine.

## 3.4.2 SOURCE CHECKPOINT 13

- Hardened the multilingual critical customer journey: catalog, filters, cart, sign-in, checkout, checkout blocks and order-success UI now use the shared `ui_text()` translation layer.
- Expanded storefront catalogs for uk-UA, en-US, de-DE, da-DK, ru-RU and pl-PL with critical commerce-flow labels and fallback messages.
- Removed hardcoded Ukrainian checkout/cart AJAX fallback messages from source and public fallback JavaScript by passing localized strings through data attributes.
- Added `bin/i18n-critical-journey-check.php` and wired it into Composer scripts, CI and the production build gate to prevent regression in critical multilingual flows.
- Re-ran release-contract, completeness, production-critical, security, commerce, backup/feed/SEO, demo, XSS and UX static gates successfully.
- Production profile is still intentionally blocked until real `composer.lock`, `package-lock.json`, `vendor/` and compiled `public/build/` are generated on a dependency-enabled build machine.


- Added a real recommendation engine with manual, hybrid and automatic modes per store.
- Automatic similar-product ranking uses shared categories, brand and matching attributes; complementary recommendations use real co-purchase order history.
- Added recommendation availability filtering and per-store recommendation settings. Recommendations moved from Foundation to Beta.
- Added read-only Developer Tools center with Route Explorer, capability/maturity inventory, runtime/DB diagnostics, queue/outbox/incidents summary and JSON diagnostic report.
- Added dedicated developer_tools.view RBAC permission. Developer Tools moved from Planned to Beta; arbitrary web PHP/shell/SQL execution is intentionally excluded.
- Database schema 43.

# 3.3.9 - 2026-09-25

- Added transactional gift cards with hashed codes, balances, expiry, immutable redemption ledger and safe restoration on cancelled/expired/refunded orders.
- Added store-scoped loyalty accounts, configurable earn/redeem rates, checkout redemption, paid-order accrual and idempotent rollback on failed/refunded flows.
- Added customer rewards history and admin rewards center with dedicated RBAC permissions.
- Gift Cards and Loyalty moved from Foundation to Beta.
- Database schema 41.

# 3.3.8 - 2026-09-25

- Added B2B company accounts with store scope, tax/VAT identifiers, currency, credit limit, payment terms and approval thresholds.
- Added company members with buyer/approver/admin roles and optional per-user spending limits.
- Added company-assigned B2B price lists and quantity-tier prices per SKU/variant.
- B2B cart/order pricing is resolved again at checkout against current price-list validity and quantity, preventing stale discounted carts.
- Added B2B invoice/deferred-payment method visible only to active company members.
- Added purchase-order number, due date, company linkage and approval state to orders.
- Added approval workflow for company orders that exceed configured thresholds, plus credit-limit enforcement.
- Added B2B administration center and explicit RBAC permissions b2b.view / b2b.manage.
- B2B system capability moved from Foundation to Beta after end-to-end core implementation.
- Added B2B Commerce regression gate. Database schema 40.

# 3.3.7 - 2026-09-25
- Added built-in commerce analytics for net revenue, AOV, carts, abandonment, top products, attribution and privacy-minimal search/zero-result reporting.
- Added a centralized release-contract gate that keeps PlatformVersion, release metadata, package metadata, theme metadata and installer documentation synchronized.
- Added `tools/prepare-locks.sh` for audited Composer/npm lock generation on a connected build machine.
- Hardened SOURCE vs PRODUCTION package classification: PRODUCTION requires real lock files, Composer runtime and Vite manifest.
- Corrected stale internal platform/demo/theme version metadata discovered during independent release audit.

# 3.3.6 - 2026-09-25

- Added reusable layout snippets stored per store and validated through the same builder schema as published layouts.
- Added one-click block duplication and insertion of saved sections in the visual builder.
- Saving a reusable section also persists the current layout as a draft so unsaved work is not discarded.
- Improved Navigation Manager with direct edit actions and a parent selector instead of manual parent IDs.
- Added server-side same-store/menu parent validation and cycle detection for nested navigation trees.
- Added `bin/builder-navigation-check.php`.
- Database schema version is now 38.

# 3.3.4 - 2026-09-25

- Completed product Reviews + Q&A lifecycle.
- Reviews now support up to four validated customer images stored through MediaImageService.
- Verified-purchase status now requires an eligible paid/processing/fulfilled/completed/shipped order containing the product.
- Added per-customer anti-flood limits and duplicate-question protection plus PublicFormSpamGuard honeypot/timing checks.
- Added unique helpful votes with idempotent counters and self-vote protection.
- Added merchant replies to reviews and customer email notification through the durable notification outbox.
- Publishing an answered product question now notifies the customer through the notification outbox.
- Admin moderation now shows review photos, helpful count and merchant reply controls.
- Database schema version is now 36.

# 3.3.3 - 2026-09-25

- Reworked the Ukrainian Nova Poshta integration to the official API 2.0 JSON endpoint and API-key request contract.
- City and warehouse selection now stores Nova Poshta Ref values that can be reused for shipment creation instead of mixing incompatible international API IDs.
- Added automatic outbound EW/TTN creation for branch and parcel-locker orders, recipient creation, cash-on-delivery backward money data, provider cancellation, live tracking checks and optional proxied carrier PDF labels.
- External carrier shipments can no longer be marked cancelled locally before provider cancellation is confirmed.
- Added compensating carrier cancellation if the external EW is created but local shipment registration fails.
- Added `bin/nova-post-api-check.php` and server-side sender profile environment settings. API keys remain server-side and are never emitted to the browser.
- Database schema remains 35.

# 3.3.2 - 2026-09-25

- Added operational shipment registry separate from generic fulfillment state.
- Added outbound and return shipments with optional RMA linkage, tracking, external carrier reference and immutable order/destination snapshot.
- Added server-enforced shipment status transitions, event history and synchronization to order fulfillment.
- Added carrier-label HTTPS reference plus safe internal A6 printable dispatch-label PDF.
- Added shipment operations center with filtering and bulk status actions.
- Added duplicate tracking protection and store/order ownership validation.
- Database schema version is now 35.

# 3.3.1 - 2026-09-25

- Added immutable order documents with per-store yearly numbering: invoice, packing slip and credit note.
- Added customer/admin HTML preview and server-side PDF export.
- Added seller tax/VAT/IBAN/bank details to store settings and document snapshots.
- Credit notes can only be issued for successful refunds; customer access is restricted to own invoice/credit-note documents.

## 3.3.1 — 2026-09-25

- Independent whole-product audit and consistency hardening. The release remains a development/source build until real database/browser/provider/load certification and a locked one-upload PRODUCTION package are produced.
- Fixed production build inputs: admin CSS Vite entries now point to real files, the admin shell uses matching manifest keys, precompression targets `public/build`, and production `.env` generation no longer relies on an unexpanded quoted heredoc variable.
- Added domain-routed Multi-Store via `mc_store_domain`; storefront context now resolves the actual request host and only falls back automatically when exactly one active store exists.
- Added persistent admin store/market/locale/currency context and a global context switcher. Product administration now uses the current store/market currency instead of hard-coded UAH.
- Added validated storefront language, market and currency preferences with persistent cookies and enabled-store checks.
- Added operational Returns/RMA: customer requests by order item, status history, admin processing, fulfillment eligibility and protection against over-returning through duplicate requests.
- Added real product comparison, saved carts, customer review submission, product Q&A submission/moderation and a Customer Experience admin center.
- Added localized Navigation Manager with header/footer/utility menus, nested items, custom/category/product/page targets and safe category fallback.
- Added storefront UI translation layer and translation catalogs for uk-UA, en-US, de-DE, da-DK, ru-RU and pl-PL; shared storefront chrome now follows the active locale.
- Added explicit module maturity states (Stable/Beta/Foundation/Planned) so unfinished capabilities cannot masquerade as completed modules; incomplete foundations remain disabled by default.
- Removed the unused GraphQL runtime dependency until a real GraphQL API exists.
- Added `bin/system-completeness-check.php` to detect missing Vite entries, broken Twig references, hard-coded admin UAH, missing domain routing, translation catalog drift and incomplete modules enabled by default.
- Added private digital-product delivery: protected file storage, per-order entitlements, payment activation, download limits/expiry, account-only access, refund revocation and inclusion in data/full backups.
- Database schema 33.

## 3.2.2 — 2026-09-24

- Admin productivity hardening inspired by proven Shopify/Wix/Shopware patterns: configurable columns and saved views for products and orders, quick preview drawers, browser-local recovery for unsaved Builder edits, and a centralized administrator activity log.
- Fixed a real multi-store access-control issue: order lists, order views and all mutating order actions are now explicitly store-scoped before business actions run.
- Administrator audit logging records only route/action metadata and never request bodies, passwords, tokens or secret field values.
- Added `system.audit.view` granular permission. Database schema 29.

## 3.2.1 — 2026-09-24

- Granular administrator RBAC with custom roles, read-only preset, store scopes and personal-data visibility permission.
- Admin navigation and risky actions now follow permissions while server-side route authorization remains authoritative and fail-closed.
- Added administrator/role management UI and protection against disabling the last active super administrator.
- Database schema 28.

## 3.2.0 — 2026-09-24
- Production packaging hardening: deterministic build script, release manifest and SHA-256 inventory requirements.
- Installer UX regrouped into Database → Store → Administrator → Demo with password visibility controls.
- Short installation guide updated to current PHP extension requirements.
- Production archive is explicitly blocked unless locked dependencies, Composer vendor and compiled Vite assets are present.

# Changelog

## 3.4.2 SOURCE CHECKPOINT 18

- Public API: додано hashed Bearer tokens, scopes, store binding, expiry/revoke, last-used та per-token rate limiting.
- Public API: додано read contracts для customers, orders і carts поруч із catalog products.
- Public API: додано idempotency storage foundation для наступних write-контрактів.
- Public API: додано CLI issuance/revoke токенів; секрет показується один раз і не зберігається у відкритому вигляді.
- OpenAPI та всі нові повідомлення API/CLI винесені тільки в canonical uk-UA локалізацію.
- Усунено прямі англійські CSRF/CRUD повідомлення з HTTP-контролерів.
- Усунено 17 місць, де неочікуваний Throwable міг показати користувачу сирий текст SQL/SDK/HTTP помилки.
- Додано public-api-contract-check.php. Public API залишається Beta до завершення idempotent write-cart, checkout та webhook-management contracts і реального E2E.
- INSTALL_UK доповнено вимогами доступу збірочної машини до npm/Packagist та production bootstrap-процедурою.

## 3.1.3 — 2026-09-24

- Added an installable premium demo storefront backed by real catalog/content entities rather than static presentation markup.
- Demo install now seeds presentation-ready categories, products, brands, articles, reviews, forum content and the DEMO10 promotion.
- Demo catalog intentionally covers real purchase states: in-stock, backorder, coming-soon/notify, promotional pricing and normal cart/checkout flows.
- Homepage now renders a functional premium hero, category shortcuts, benefits, product grid, promotional cards, brands, blog/news and contact CTA from runtime data.
- Product cards now use real AJAX add-to-cart with CSRF protection, live cart-count updates and no-JS/server fallbacks retained by the underlying cart flow.
- Added configurable storefront primary/accent/success color tokens in Appearance settings.
- Refined the visual system across product, cart, checkout and content surfaces with restrained shadows, hierarchy and responsive states.
- Runtime storefront CSS remains under the existing 14 KB Brotli budget; the budget was not raised for the redesign.
- Added `bin/demo-showcase-check.php` to prevent regressions in demo install, functional CTAs, theme tokens and storefront UX contracts.

## 3.1.2 — 2026-09-24

- Added verified backup upload from PC; database/data backups restore in admin after an automatic safety snapshot, while full-system uploads are staged for emergency CLI restore.
- Added explicit product purchase states, ETA labels, backorder/preorder checkout support and double-opt-in back-in-stock notifications.
- Connected published Product Builder revisions to the real product storefront with safe fallback to the default layout.
- Added Checkout Builder runtime ordering, optional sections, contact-field requirements and left/right/full placement.
- Added AJAX cart quantity/remove operations with server-authoritative subtotal, discount and total recalculation and non-JS fallback.
- Added Contact/Inquiry Center with manager/customer notifications and admin workflow.
- Added customer group assignment and promotion targeting for default/vip/wholesale/custom groups plus guest shoppers.
- Database schema version is now 26.

## 3.1.1 — 2026-09-24

- Added Backup Center profiles for database-only, store-data (database + media) and full application backups, with server retention, download-to-PC, integrity verification and guarded admin restore for data-safe profiles.
- Added pre-restore full safety snapshot and kept full application restore on the dependency-light emergency recovery CLI to avoid a running web process replacing its own code/vendor.
- Expanded catalog CSV import/export with brand, full description and SEO slug while retaining ProductWriter validation/events.
- Added canonical product export projection and Feed Center for Google Merchant RSS/XML, Meta/Facebook CSV, Pinterest CSV, TikTok Catalog CSV, Rozetka XML, Prom.ua YML plus generic CSV/JSON.
- Added marketplace category mapping, feed validation, variant-per-offer export and atomic static feed snapshots generated by admin/CLI instead of on public crawler requests.
- Added `commerce:feeds:generate` for cron/worker feed refreshes.
- Added `robots.txt`, sitemap index and paged locale/store sitemaps sourced from canonical indexable SEO routes.
- Added DOM/XML as an explicit installer/preflight dependency.
- Feed projection preloads media, categories and attributes in bounded batches to avoid per-product N+1 queries; TikTok uses its own `sku_id`/availability serialization and image requirements instead of reusing Google-style values.
- Added a covering sitemap index on `(store_id, locale, indexable, id)` for large route sets.
- Database schema version is now 25.

## 3.1.0 — 2026-09-24

- Added server-side promotion/coupon engine with transactional checkout recalculation, automatic discounts, coupon preview, usage/per-customer limits, priorities and product/category targeting.
- Added admin Promotions center and discount snapshots/redemption audit on orders.
- Added catalog CSV export plus preview/apply import through ProductWriter validation/events, and event-safe product bulk publish/draft/archive.
- Added unified Notification Center and optional SMS HTTPS gateway with SSRF protection; order-created SMS is queued only when SMS is explicitly enabled.
- Added double-opt-in newsletter subscriptions, signed unsubscribe links and batch-worker email campaigns for confirmed subscribers only; campaign fan-out no longer runs inside the admin HTTP request.
- Improved responsive order-created email with item rows and discount display.
- Added optional OpenAI Responses API and Gemini generateContent authoring helpers; both providers are OFF by default and never save product content automatically.
- Browser installer now writes all new SMS/AI runtime variables with safe OFF defaults.
- Database schema version is now 24.

## 3.0.9 — 2026-09-24

- Added full release static journey gate for storefront/admin routes, CSRF-protected POST forms, email notification templates and responsive breakpoint contracts.
- Fixed dead product-brand navigation: brand now opens the catalog filtered by the actual brand identifier.
- Fixed the mobile sticky purchase button and made its action independent from IntersectionObserver availability.
- Replaced the non-functional product delivery-preview button with a real delivery-information navigation.
- Fixed responsive overflow in Migration Center long CLI examples, information-page tables and mobile order-management layouts.
- Chromium responsive simulation covers 66 templates/major blocks at 1440/1024/768/390 px (264 viewport cases) with no horizontal overflow after fixes.
- Browser interaction simulation covers quantity controls, sticky purchase, checkout carrier switching, admin mobile sidebar, command palette and confirmation dialog.
- Database schema remains 23.

## 3.0.8 — 2026-09-24

- Fixed clean-install runtime configuration completeness: browser setup now writes every environment key required by the current Symfony container with safe OFF/default values for optional integrations.
- Disabled Google Commerce and Marketing Hub providers no longer enqueue integration jobs.
- Added integration queue lease fields and stale-processing recovery after worker crashes.
- Added unified background queue health diagnostics, including stalled jobs and backlog age.
- Added bounded retention command that purges only successful old background records and preserves unresolved failures.
- Added production-critical autonomous release gate.
- Database schema version is now 23.

## 3.0.7 — 2026-09-24
- Added Asset Performance Hardening without increasing the runtime requirements of small stores.
- Split admin CSS from storefront CSS: the compatibility storefront stylesheet dropped from about 99.6 KB to about 60.2 KB raw, while admin pages now load a dedicated core stylesheet.
- Builder and Media Library styles are isolated into a feature stylesheet loaded only on those screens.
- Moved Builder and Media Library inline JavaScript into dynamically imported feature modules; normal admin pages do not download or initialize them.
- Expanded Vite production inputs and explicit vendor chunking for Vue/Pinia, Tiptap, Chart.js and Lucide; CSS code splitting, tree-shaking and minification remain build-time operations.
- Added source/compiled asset budgets with Brotli-size regression checks and a production asset precompression utility.
- Hardened Nginx cache guidance: immutable one-year caching is only used for content-hashed assets; stable compatibility assets use short revalidation.
- Added optional Gzip static/Brotli deployment guidance and `docs/ASSET_PERFORMANCE.md`.
- No database schema change; schema remains 22.

## 3.0.6 — 2026-09-24
- Added optional Meilisearch search accelerator with automatic fail-soft SQL fallback; MySQL/MariaDB remains authoritative.
- Added durable storefront product read projections used as the canonical external-search indexing source.
- Added incremental search-index refresh on product changes and order reservation/cancellation events plus full `commerce:search:reindex` recovery command.
- Added precomputed popular facet projections with transparent live-SQL fallback and `commerce:storefront:warm-facets`.
- Added cursor/keyset storefront catalog API for deep traversal without large SQL OFFSET scans.
- Added opt-in Redis and Valkey scale profiles for shared cache/session state while keeping filesystem/single-node defaults for small stores.
- Added database observability command for connection, slow-query, InnoDB read/lock and buffer-pool counters.
- Added separated Supervisor worker topology example and k6 storefront load profile for 50/250/1000+ VU validation.
- Updated Vue 3.5.42 to stable 3.5.43; no RC/beta frontend dependency was introduced.
- Database schema version is now 22.

## 3.0.5 — 2026-09-24

- Added bounded storefront application caching for category navigation, catalog pages, facets and product pages; purchase flows still revalidate live stock and price.
- Added scale-oriented composite indexes for translation joins, primary variant lookup, review aggregation, price context and attribute lookups.
- Added `config/performance/concurrency.json`, `docs/SCALABILITY.md` and `bin/catalog-scale-audit.php`.
- Formalized 5k-50k products as the normal design target while keeping concurrency claims dependent on real load tests.
- Documented Redis/Valkey, shared sessions, CDN/static delivery, dedicated workers and DB tuning as the production path for sustained high concurrency.
- Database schema version is now 21.

## 3.0.4 — 2026-09-24

- Security hardening adds a centralized outbound URL policy for administrator/configuration supplied HTTPS endpoints; private, loopback, link-local and credential-bearing URLs are rejected to reduce SSRF exposure.
- Extension ZIP inspection now rejects suspicious per-entry compression ratios in addition to path traversal, symlinks, file-count, per-file and total uncompressed-size limits.
- Added persistent payment-webhook replay protection. A successfully claimed Monobank callback cannot mutate payment state twice; failed processing releases the claim so legitimate provider retry remains possible.
- Added repeatable security regression checks covering SSRF URL policy, HMAC clock-skew/signature validation, ZIP hardening markers, raster re-decode/decompression guards and response security headers.
- Integration retry/backoff policy is centralized and independently testable; eight failed attempts remain the dead-letter boundary.
- Added runtime failure-isolation contract checks for shipping-provider degradation, optional search relevance, integration queues, notification enqueueing and last-known-good storefront fallback.
- Added explicit 10k/100k/500k performance budget profiles and a live HTTP p95 smoke runner. SOURCE builds without a running database/storefront report static-readiness only and do not fabricate benchmark results.
- Database schema raised to 20 for `mc_webhook_replay`.

## 3.0.3 — 2026-09-24

- Google Merchant API Products v1 worker with API data-source upsert/delete, OAuth service-account/static-token transport, canonical catalog projection and persisted Merchant diagnostics.
- Integration worker with atomic claiming, exponential retry, dead-letter state and CLI command `commerce:integration:work`.
- Marketing Hub server-side adapters for GA4 Measurement Protocol, Meta Conversions API and TikTok Events API; all providers are disabled by default.
- Consent-aware event gate: analytics/marketing events are never sent without an explicit consent snapshot; no-consent events are recorded as skipped.
- Provider/event deduplication prevents double delivery after queue retries. Raw email/phone values are not sent to Meta/TikTok; normalized hashes are used instead.
- Admin integration diagnostics for Merchant product issues, marketing delivery status and manual dead-letter retry.
- Added persistent `mc_google_merchant_product_state` and `mc_marketing_delivery` state tables.

## 3.0.2 — 2026-09-24

- Migration Center отримав реальний read-only OpenCart/ocStore 3.x pipeline для мов, валют, брендів, категорій, характеристик, товарів, опцій, акцій/знижок, SEO URL, клієнтів, історичних замовлень і медіа.
- Dry-run тепер виявляє дублікати SKU/GTIN, некоректні ціни, відсутні переклади, зламані/циклічні категорії, відсутні зв’язки та проблеми історичних замовлень; окремий target conflict report показує конфлікти з уже наявними SKU/GTIN та повторне використання customer email.
- Імпорт поєднує mc_migration_run з mc_import_job/item/id_map/issue: stable source identity, idempotent mapping, restart-safe resume та детальний ledger без дублювання механізмів стану.
- Rollback обмежений конкретним migration run і видаляє лише створені ним commerce-сутності; reused records не видаляються. Глобальні locale/currency registries залишаються для захисту інших store contexts.
- Legacy customer password hashes не переносяться; історичні orders зберігають immutable payment/shipping/address/status snapshots.
- Media copy використовує canonical path validation і блокує path traversal за межі явно заданого source image root.
- Додано CLI commerce:migration:opencart для dry-run/apply/resume/rollback без збереження пароля source DB та admin Migration Center з історією запусків і CSRF-захищеним rollback.

## 3.0.1 — 2026-09-24

- Added a functional Media Library admin UI with folder creation, search, multi-upload drag-and-drop, title/alt/tags/focal-point editing, safe usage-aware deletion and JSON picker endpoint.
- Expanded Home/Header/Footer/Product Builder with add/remove/reorder, property/style/responsive editors, live desktop/tablet/mobile preview and Media Library selection.
- Added explicit draft workflow for layouts; drafts do not affect storefront until publish. Existing publish validation and rollback remain atomic.
- Media deletion now protects assets referenced by product media/documents or saved layout revisions.


## 3.0.0 — 2026-09-24

- Core license changed from proprietary metadata to MIT so the base platform can be self-hosted, modified and redistributed without a mandatory commercial runtime license.
- Added a dependency license gate for Composer/npm lock files; unknown or non-approved runtime licenses block a release build until reviewed.
- Added Theme Token System foundation with safe Core -> theme -> user override inheritance and CSS-injection-resistant token validation.
- Added validated revision-backed Home/Header/Footer/Product layout builders, Safe Layout fallback and a first drag-and-drop admin ordering UI with rollback history.
- Added immutable `releases/current/shared` deployment layout plus an atomic `current` symlink switcher and failure injection points.
- Added executable rollback failure-injection checks across every deployment phase; a failed deployment must restore the previous `current` target.
- Migration Center now has a persistent resumable run journal with cursor/checkpoint/error state, complementing dry-run and OpenCart/ocStore source readers.
- Added durable integration sync queue; Google Commerce catalog updates and Marketing commerce events enqueue independently of checkout/catalog writes.
- Media Library foundation now stores folders, alt/title, focal point and tags independently from binary derivatives.
- Added locale fallback-chain service and resilient currency rate resolution: provider failures fall through to other providers or last-known-good rates; explicit stored prices remain authoritative.
- Extension Settings Schema v2 adds color/range/multilingual fields, groups, placeholders, advanced flags and numeric steps; manifest schema adds license/price/signature/healthcheck/migrations/uninstall policy metadata.
- Added CI gates for static security patterns, SEO baseline, dependency licenses and deployment rollback failure injection.
- Database schema raised to 18.

## 2.9.0 — 2026-09-23

- Архітектурне ядро отримало справжній Domain Event Bus поверх Transactional Outbox: бізнес-подія записується в тій самій DBAL-транзакції, що й замовлення, платіж, товар або реєстрація клієнта; додаткові listeners не виконуються inline і не можуть відкотити успішну бізнес-операцію.
- Додано versioned event-contracts v1 для order.placed, order.cancelled, order.completed, payment.status_changed, catalog.product_created, catalog.product_updated і customer.registered.
- Domain-event worker отримав окремий delivery ledger для кожного subscriber, bounded retries, exponential backoff, stale-lock recovery та dead-state. Помилка одного subscriber ізолюється і не блокує інших.
- Notification flow переведено на підписку до order.placed; email/Telegram залишаються durable і idempotent через dedupe key. SMTP/Telegram не є умовою успішного checkout.
- Додано post-response deferred-work drain: невеликий batch може бути оброблений після відправлення HTTP-відповіді, але durable CLI workers залишаються авторитетним retry-шляхом.
- Адмінка отримала contextual RBAC foundation: permission catalog, role→permission mapping і store scope. Non-super-admin authorization fail-closed при помилці або невизначеному store context.
- Додано Public REST API v1 foundation для читання каталогу з contract version headers, RFC 7807-style errors та базовою OpenAPI 3.1 схемою. Headless API є опціональним і не є залежністю стандартної SSR-вітрини.
- Додано Extension SDK v1: manifest schema з SemVer/core/extension-api compatibility, execution modes declarative/remote_app/trusted_release, CLI scaffold, declarative settings schema, автогенерація форм налаштувань, encrypted secret values і revision history з rollback.
- Виконуваний third-party PHP/JS як і раніше не активується з довільного ZIP: він потрапляє у quarantine; trusted executable code допускається тільки через підписаний release channel.
- Додано architecture boundary test і явний baseline дозволених cross-module infrastructure dependencies. Нові прямі залежності Domain/Application → чужий Infrastructure мають пройти архітектурний review.
- Додано централізований SecretVault на libsodium для секретів extension settings; помилка optional extension settings fail-soft і не повинна валити Core/storefront.
- Зафіксовано technology decisions 2026: PHP Core лишається сумісним з 8.4–8.5; Symfony 8.1 не знижується до 7.x; Doctrine DBAL залишається основою persistence; MySQL/MariaDB — zero-extra-service baseline; Redis/Valkey, Meilisearch/OpenSearch, GraphQL, Docker/Kubernetes і повний headless — опціональні шари, а не обов'язкові залежності.
- Версію платформи та schema піднято до 2.9.0 / 17.

## 2.8.0 — 2026-09-17

- Search/Filter отримав керовані групи синонімів, ізольовані за магазином і локаллю; пошук продовжує працювати без synonym-layer при відсутній/пошкодженій таблиці.
- Багатослівний пошук зберігає AND-семантику між словами, але всередині кожного слова підтримує OR-розширення синонімами з контрольованими лімітами SQL.
- Додано фасетні фільтри за типізованими характеристиками товару: boolean, decimal/number і text; кілька значень одного атрибута працюють як OR, різні атрибути — як AND.
- Фасети будуються лише для attribute_definition.filterable=1, мають bounded limits і не перетворюють довільні характеристики на неконтрольований query workload.
- Nested attr-фільтри проходять серверну whitelist-валідацію; пагінація зберігає складені attr-параметри через RFC3986 query string без втрати обраних значень.
- В адмінці каталогу додано окремий розділ «Пошук» для створення/видалення груп синонімів з CSRF та fail-soft поведінкою.
- Fresh-install demo тепер створює filterable characteristics, щоб фасетний пошук було видно одразу після встановлення.
- Dynamic catalog filters залишаються noindex,follow; SEO-фільтри з індексованими landing URL навмисно не змішані з runtime-фасетами.

## 2.7.0 — 2026-09-17

- Order Manager перетворено на повноцінний операційний центр: пошук, фільтри статусів замовлення/оплати/доставки, керований page-size, компактна пагінація та детальна картка замовлення.
- Додано безпечне керування fulfillment-станами з whitelist переходів, tracking number, автоматичним переходом замовлення у processing/completed і забороною модифікації скасованих/повністю повернених замовлень.
- Додано внутрішні нотатки менеджера як незмінні order events; вони не надсилаються покупцю і залишають audit trail у хронології замовлення.
- Додано ручне завершення замовлення з інваріантами: оплата має бути підтверджена, а фізична доставка — delivered; digital fulfillment може бути not_required.
- Ручне підтвердження оплати тепер підтримує bank transfer та cash on delivery; успішна операція не залежить від SMTP/Telegram.
- Додано customer order-status notifications через durable outbox, окремий адаптивний email template та ручне повторне надсилання з картки замовлення.
- У картці замовлення додано observability черги повідомлень: канал, статус, кількість спроб, дата та безпечний tooltip останньої помилки.
- PaymentRefundService отримав часткові повернення, контроль доступного залишку з урахуванням уже підтверджених і pending refunds, idempotent refund ledger та статус partially_refunded.
- Повторні payment success webhook більше не можуть випадково перезаписати partially_refunded/refunded state назад у paid.
- Виправлено order-created email: сума тепер коректно відображається з фактичних total_minor/currency context fields.
- Всі order mutations виконуються локально транзакційно; помилка notification backend не відкатує оплату, доставку, нотатку чи завершення замовлення.

## 2.6.0 — 2026-09-17

- Core Update доведено до реального fail-safe apply pipeline: verified Recovery Point, maintenance barrier, migration execution до активації нового релізу, atomic Core switch, HTTP smoke probe нового релізу та автоматичне відновлення snapshot при помилці.
- Додано одноразові probe-токени для перевірки нового релізу через реальний HTTP entry point без відкриття maintenance mode для відвідувачів.
- Додано CLI-команди для staging/apply/list Core Update, щоб оновлення можна було виконувати контрольовано без залежності від браузерного запиту.
- Catalog CRUD розширено окремими сервісами variants, typed attributes і product documents; операції перевіряють цілісність, історичні посилання та небезпечне видалення.
- В адмін-формі товару додано робочі секції керування варіантами, характеристиками та документами.
- Вітрина товару використовує актуальні варіанти та не покладається на один фіксований SKU/залишок.
- Customer Account отримав email verification, resend verification та безпечне password recovery/reset через одноразові хешовані токени з TTL, single-use семантикою і rate-limit видачі.
- Публічні відповіді password recovery не розкривають, чи існує email; збій notification backend не ламає сторінку відновлення.
- Додано міграцію для customer account security tokens і тести token-store/update probe foundation.
- Продовжено fail-soft політику: помилки optional notification/security flow не повинні виводити storefront з ладу або пошкоджувати чинний акаунт.

## 2.5.0 — 2026-09-17

- Усилена безопасность Core Update: staged release проходит синтаксическую проверку всех PHP-файлов через TOKEN_PARSE, проверку JSON/YAML, структуры релиза, версии и built assets до любого изменения рабочей системы.
- Перед переключением Core Update теперь дополнительно проверяет созданный Recovery Point; непроверенный backup блокирует обновление.
- Расширен поиск каталога: название, краткое описание, SKU, GTIN, MPN, бренд и текстовые характеристики; многословный запрос обрабатывается как набор обязательных токенов, точные SKU/GTIN/MPN получают приоритет.
- Добавлены безопасные фильтры витрины по бренду, наличию и диапазону цены, сортировки по цене/названию/новизне и адаптивный UX фильтров. Обычные query-фильтры получают noindex,follow и не создают SEO-мусор.
- Исправлена согласованность версии сборки во внутренних metadata/runtime-файлах.
- Добавлен отдельный customer firewall и полноценная базовая витрина личного кабинета без влияния на guest checkout: регистрация, вход/выход, профиль и история только собственных заказов.
- Заказы, оформленные авторизованным покупателем, теперь безопасно связываются с customer_id на сервере; customer_id из пользовательского POST не принимается.
- Добавлен список желаний, изолированный по покупателю и магазину, с защитой CSRF и проверкой доступности товара.
- Добавлено GDPR self-service: экспорт персональных данных и создание контролируемого erasure-request вместо опасного мгновенного удаления юридически значимой истории заказов.

## 2.3.0 — 2026-09-17

- reliability: added a dependency-free pre-framework emergency handler for fatal bootstrap errors; safe GET storefront requests can serve the last-known-good HTML snapshot, while API/admin receive controlled 503 responses instead of raw fatal output;
- reliability: configuration revisions now verify SHA-256 integrity and automatically skip corrupted revisions, falling back to the newest valid revision or safe defaults;
- extensions: added quarantine-first package handling, stricter ZIP/CSS validation, atomic theme preparation, previous-version rollback and a one-click emergency isolation mode that disables only optional packages while leaving built-in Core functionality intact;
- extensions: failed theme activation no longer replaces the currently active theme; the active pointer changes only after assets are prepared successfully;
- site profiles: added independent shop, catalog, content, landing, forum and hybrid modes. Features can be enabled later without reinstalling or deleting data;
- forum: added database-backed boards, topics and replies, guest moderation queue, rate limiting/honeypot protection and an admin moderation screen. Forum is optional and feature-gated;
- storefront: reference visual design is no longer tied to the demo flag. Real categories now supply tile images from catalog media, while hero, promo blocks, products and articles keep the same responsive storefront structure after demo data is replaced;
- storefront: homepage catalog/blog blocks are fault-isolated; a failing optional block is omitted without taking the complete homepage down. Added progressively enhanced live-search suggestions and a mobile navigation drawer with non-JS fallbacks;
- storefront: base Twig helpers now fail safely to empty navigation/default presentation/default capabilities if optional presentation or navigation queries fail;
- admin: added store identity/requisites settings with atomic save and revision rollback; locale, currency, timezone, contacts and legal data are configurable without editing files;
- admin UX: retained responsive sidebar, command palette, toast notifications, modal confirmations, dirty-form protection, AJAX health checks and image previews; added recovery controls and forum management;
- media: retained validated raster upload, JPEG fallback, optional WebP/AVIF derivatives, responsive sizes, focal point, product gallery metadata and orphan cleanup. Optional codecs cannot abort a product save;
- demo/install: demo remains enabled by default for a fresh showcase install; forum boards are seeded empty so the forum can be enabled later without reinstalling the platform.

## 2.2.0 — 2026-09-17

- installer: розширено browser preflight для PHP 8.4–8.5, обов’язкових розширень, HTTPS, прав запису, memory/disk, MySQL/MariaDB, InnoDB, utf8mb4 і strict SQL mode; конфігурація записується атомарно; одноразовий install request знищується після використання; повторна інсталяція поверх встановленої бази блокується;
- installer: додано післяінсталяційну перевірку магазину, ринку, адміністратора, uk-UA, UAH, складу, базових сторінок і податкового класу та команду `commerce:install:verify`;
- catalog: базовий CRUD товарів і категорій доведено до реальних create/edit/delete сценаріїв із CSRF, перевірками залежностей та захистом історії замовлень;
- catalog: додано безпечне копіювання одно-варіантного товару в чернетку з новим SKU/URL, без дублювання GTIN та без перенесення фізичного залишку;
- catalog: у формі товару додано вибір активного бренду; бренд зберігається при створенні/редагуванні та перевіряється у межах магазину;
- admin: дії копіювання/видалення додано в таблиці товарів і категорій; руйнівні дії мають підтвердження;
- storefront: source/public CSS синхронізовані; додано стилі компактних рядкових дій у каталозі.

## 2.1.1 — 2026-09-17

- Synced the directly served storefront CSS with the v2.1 reference storefront styles so the installed shop actually uses the new header, hero, benefit strip, category tiles, promo cards and responsive layout.
- Synced checkout source/public JavaScript to retain delivery field state fixes in both source and served assets.
- Re-ran PHP syntax checks and demo-media reference checks.

## 2.1.0 — 2026-09-17

- Rebuilt the default storefront around the approved modern retail reference: two-level header, wide search, category navigation, visual hero, trust strip, visual category tiles, compact product grid and side promotional banners.
- Added store-level presentation settings persisted in extension metadata and a protected admin page at `/admin/appearance/storefront` for header, hero, home blocks and promo banners.
- Added dynamic top-category navigation to the storefront shell without hard-coding demo categories into the normal runtime.
- Expanded optional fresh-install demo from 4 categories / 6 products to 8 categories / 10 products while keeping the installer checkbox enabled by default.
- Added reference-derived demo media for furniture, beauty, sports and kids categories plus presentation promo/hero assets.
- Kept the storefront SSR-first and progressively enhanced; no SPA or mandatory external search/runtime dependency was introduced.
- Default public storefront is light-first for predictable commercial presentation.

## 2.0.0 — 2026-09-17

- Added optional presentation demo install/remove commands with isolated demo metadata.
- Browser installer can create the demo automatically without changing the clean-install default architecture.
- Added 6 real catalog products, 4 categories, 6 demo brands, published reviews, attributes, related-product links and stock/pricing data.
- Added WebP demo media, gallery images, a responsive storefront hero and presentation blocks.
- Added 3 real blog articles backed by Content + SEO Router and a real `/blog` index.
- Added demo content for required information pages with explicit non-production legal warning.
- Reworked storefront header, search, product cards, home sections, articles and footer for a modern responsive showcase.
- Added catalog search by product title, SKU and brand without creating indexable search URLs.
- Product cards now expose review summary; product pages derive key features from attributes and load related products from the database.
- Removed legacy demo-only product/blog controllers so the showcase uses the same runtime as real data.

## 1.9.0 — 2026-09-17

- Added hosted mono acquiring for UAH with cards, Apple Pay and Google Pay handled on the provider payment page.
- Added ECDSA verification of mono `X-Sign` webhooks with cached provider public key and refresh-on-verification-failure.
- Added payment event ordering by provider `modifiedDate`; stale/out-of-order webhooks are ignored.
- Added payment lifecycle transitions that atomically commit inventory on successful payment and release active reservations on failure/expiry/cancellation.
- Cash on delivery now confirms the order and commits inventory without pretending that payment is already received.
- Added manual bank-transfer confirmation, order cancellation, full provider refund request and payment refund ledger.
- Added `/admin/orders` list/detail runtime with immutable order snapshots and event history.
- Added unpaid-order reservation expiry command `commerce:orders:expire-unpaid`.
- Added payment return/status page that never trusts browser redirect as proof of payment.
- Database schema version is now 12.


## 1.8.0 — 2026-09-17

- Українська мова стала явним UI default: Symfony locale `uk-UA`, installer, CLI-install, email-шаблони та підготовлена Vue-адмінка.
- Основна інструкція встановлення переписана українською; англійська збережена окремо.
- Додано короткий `INSTALL_UK.txt` для завантаження на сервер.
- Installer тепер чітко пояснює різницю між source ZIP і повним production-release з Composer `vendor`.
- Версії package/release/theme/runtime синхронізовані на 1.8.0.
- Системні URL і технічні API-коди навмисно залишаються стабільними англійськими.

## 1.7.0 — 2026-09-17

- Added real checkout order placement with CSRF and idempotency protection.
- Added atomic order snapshots, inventory reservations, fulfillment and payment records.
- Added Payment Provider contract with cash-on-delivery and bank-transfer providers.
- Added digital-cart checkout path without physical delivery.
- Added customer Email and manager Telegram notifications through the existing outbox.
- Cart is converted only after a successful transaction; reservations are held for the order.
- Added schema migration for checkout idempotency on sales orders.

## 1.6.0 — 2026-09-16

- Added standalone browser setup bootstrap with PHP/extension/release-completeness checks.
- Added secure `.env.local` generation with random APP_SECRET and tested database connection.
- Added optional database creation when the configured database account has permission.
- Added one-time web-to-canonical-installer handoff; the initial administrator password is removed from disk immediately after the attempt.
- Added installation lock preventing reuse of the browser installer after success.
- Added Apache public front-controller configuration, hardened Nginx example and explicit production deployment/install documentation.
- Added `bin/release-check.php` so a source archive missing Composer runtime dependencies cannot be mistaken for a production release.
- Added Symfony Dotenv as a runtime dependency so `.env.local` is a supported deployment configuration source.
- Clarified that production release archives contain `vendor/` and built frontend artifacts; source archives are not treated as one-upload releases.
- No post-install file moves are required: the domain remains pointed to the application `public/` directory.

## 1.5.0 — 2026-09-16
- Added the first database-backed public storefront: home, catalog, category and product pages now read the same published catalog records written by administration.
- Central SEO routing now resolves published product/category URLs and preserves historical 301 aliases on the real storefront.
- Added market-aware storefront pricing, availability, tax display, breadcrumbs, Product/Offer structured data, real attributes/documents/reviews and responsive product cards.
- Added persistent anonymous carts stored in `mc_cart`/`mc_cart_item` with a random HttpOnly SameSite=Lax cookie, 7-day expiry, exact fixed-point quantities and server-side stock/min/max/step validation.
- Hardened cart add/update/remove with database transactions and row locks; browsing a cart does not reserve inventory.
- Made “Buy now” atomically add the selected quantity and continue directly to checkout instead of opening an empty checkout.
- Replaced the checkout demo route with the real `/checkout` runtime using the persistent cart and live carrier city/point discovery. Nova Post, Ukrposhta, Meest and Delivery keep manual fallback when their APIs fail.
- Added stable `system_key` identity for required information pages, real public page rendering and a minimal administration editor with server-side HTML sanitization. Draft/empty legal pages are noindex and never pretend to contain finished legal text.
- Removed unfinished Brands/Blog links from the primary storefront navigation until their production routes are connected.
- Fixed Product JSON-LD image URLs to use absolute public URLs while preserving relative storefront rendering.
- Avoided cart-row duplication when accidental multiple primary media relations exist by selecting one deterministic primary asset.
- Added schema v11 for stable system content keys.

## 1.4.0 — 2026-09-16
- Added the first real transactional installer: preflight -> Doctrine migrations -> default store/market/warehouse/admin seed.
- Added installation singleton state to block accidental reinstallation.
- Added administrator identities, database-backed Symfony authentication, login throttling and CSRF-protected login/logout.
- Added store legal/contact profile bootstrap and active consent-policy bootstrap.
- Fixed clean-install ordering by seeding uk-UA and UAH before later measurement/content migrations that reference them through foreign keys.
- Added real category and product write models. Product creation is atomic across translation, default variant, price, inventory, categories, market/store publication and SEO route.
- Added product/category list, create and edit administration pages with search and pagination.
- Added product lifecycle states (draft/published/archived) and safe category activation/deactivation.
- Added PATCH admin APIs for catalog updates with CSRF protection.
- Added server-side HTML sanitization for product descriptions before persistence.
- Made fractional sale units drive the default quantity step/minimum instead of forcing every product to 1.000000.
- Added category cycle prevention and stable manual SEO URL editing with existing 301 history.
- Updated CI to execute the real installer and an integration flow: install -> category -> product -> price -> stock -> SEO -> publish/update.
- Added schema v10 for installation/admin runtime state.

## 1.3.0 — 2026-09-16
- Simplified Core: removed product recall, lot/batch/serial traceability, EPREL/energy-label, DPP, EPR/WEEE/battery and cross-border customs workflows from the default platform. These are extension-level concerns, not universal commerce Core.
- Removed development-era deprecated checkout address/alias compatibility code.
- Standardized all system-page URLs on stable English paths while keeping visible titles localized. Examples: `/cart`, `/checkout`, `/account`, `/shipping`, `/about-us`, `/privacy-policy`, `/cookie-policy`, `/terms-and-conditions`.
- Standardized entity namespaces on `/brand/...` and `/blog/...` for every locale.
- Added compact Information Page catalog and footer groups for About, Contact, Shipping, Payment, Returns, Warranty, FAQ, Privacy, Cookies and Terms.
- Added required-store-field metadata so legal/information pages can be checked before publication instead of shipping empty placeholders.
- Simplified the default product editor and product-page composer by removing niche compliance fields and blocks.
- Added schema v9 cleanup migration for upgrades from development builds that contained the removed niche tables.
- Database schema version is now 9.

## 1.2.0 — 2026-09-16
- Added explicit `price_only` B2C display: storefront shows only the final payable price with no VAT wording while tax remains fully calculated/snapshotted internally.
- Made `price_only` the default consumer tax-display policy; B2B net/gross and detailed VAT modes remain available.
- Made canonical storefront slugs ASCII-only by platform policy; generated and manual slugs are transliterated, lowercase and hyphenated.
- Removed native UTF-8 canonical slug mode to prevent percent-encoded Cyrillic/Unicode-looking storefront URLs.
- Added localized Ukrainian public system-page route catalog for catalog/cart/checkout/account/search/legal/content pages.
- Reserved all configured system-page roots against product/category/CMS slug collisions.
- Added system-route policy persistence and schema v8 migration.
- Fixed migration namespaces for schema v6/v7 so Doctrine Migrations discovers them under the configured `Commerce\Migrations` namespace.

## 1.1.0 — 2026-09-16
- Server-side consent receipts use anonymous client identifiers and HMAC proofs instead of raw IP storage.
- GDPR data-subject request tracking, per-domain retention policies and channel-specific marketing consent.
- EU/Ukraine cross-border customs profile with HS/CN/TARIC, country of origin, net weight and DAP/DDP market policy.
- Added protected Privacy & Consent, Consumer Rights, Product Compliance and Accessibility system modules.
- Added EU-strict cookie/consent model with separate necessary/preferences/analytics/marketing purposes, versioned consent receipts and cookie registry.
- Added Google Consent Mode v2 projection and storefront defaults that deny analytics/advertising storage until the shopper grants the relevant consent.
- Added versioned Legal Document Center for privacy, cookie, terms, withdrawal/returns, warranty and accessibility documents.
- Added market-level consumer policy, immutable checkout acknowledgements, digital-content performance/withdrawal consent and explicit payment-obligation checks.
- Added durable price history and lowest-prior-price calculation for EU price-reduction announcements.
- Added typed compliance schemes/records instead of a single generic certificate field: CE marking, EU DoC, certificates, test reports, SDS, EPREL, energy label, DPP, EPR/WEEE/battery registrations.
- Added dedicated EPREL/energy-label profile and storefront block designed for placement close to price.
- Added Digital Product Passport storage, lot/batch/serial traceability, order-line trace records and product recall records.
- Added Accessibility Profile targeting EN 301 549 / WCAG 2.2 AA and a versioned accessibility statement.
- Product editor schema now exposes consumer-rights, traceability, certification, EPREL, energy label and DPP fields only through dedicated groups.
- Product page composer schema v2 adds recall notice, compact energy label and consumer-rights blocks.
- Storefront now includes a responsive, equal-choice cookie consent surface and persistent privacy-settings entry point.
- Database schema version is now 7.

## 1.0.0 — 2026-09-16
- Added protected VAT/tax foundation with effective-dated tax classes/rates, market B2C/B2B display policies and integer-minor-unit tax calculation.
- Added gross, gross-with-tax-breakdown, net-with-gross and restricted net-only presentation modes; EU/UA consumer default is gross-first.
- Added immutable order tax snapshots and store/customer tax registration/profile records.
- Added EU product-compliance entities for manufacturer/economic operator, EU responsible person, localized warnings/safety information and regulatory identifiers.
- Added first-class product documents and relation types for related/accessory/alternative/upsell/cross-sell/spare/frequently-bought scenarios.
- Added variant shipping dimensions and shipping class.
- Added schema-driven product-editor definition covering identity, content, categories, pricing/tax, units, variants, inventory, media, attributes, shipping, SEO, Google, compliance, documents and relations.
- Product page now supports VAT breakdown and a compliance/safety block.
- Google Merchant/structured-data price builders prefer the tax-inclusive canonical merchant/gross price when supplied.
- Database schema version is now 6.
- Lowered the certified PHP baseline to PHP 8.4 while keeping Symfony 8.1; PHP 8.4 and 8.5 are the current certified runtime range.
- Added shared install/update preflight service and `commerce:system:check` for PHP extensions, filesystem and database requirements.
- Added measurement-unit registry, translations, UNECE/Google unit mappings, fractional sale steps and unit-pricing fields.
- Added CMS/blog content storage and product-review storage with moderation, verified-purchase flag and review media relations.
- Added deliberate SEO facet landing registry plus indexing policy: sorting/runtime filters are noindex; pagination is self-canonical; empty filter sets are 404; promoted facets can be indexable.
- Added structured-data graph builders for Organization, WebSite, BreadcrumbList, Product/Offer, ProductGroup, Review/AggregateRating and BlogPosting.
- Added reciprocal hreflang/x-default output support in the storefront SEO head.
- Added visible breadcrumbs, an accessible product gallery with next/previous/fullscreen dialog, and a lightweight reusable CSS-scroll-snap slider.
- Added Tiptap 3.31.3 Vue editor dependencies and reusable administration rich-text editor; server-side HTML sanitization remains mandatory.
- Switched default fonts to a system-first stack; self-hosted WOFF2 variable fonts are supported without a mandatory external font request.
- Added update checkpoint storage and explicit safe update/rollback phase model.
- Database schema version is now 5.

## 0.9.0 — 2026-09-16
- Added central SEO route registry for products, categories, brands, CMS/landing pages and blog articles.
- Made the central route registry authoritative; legacy translation-table slug fields are now nullable import/backfill compatibility fields and no longer enforce competing uniqueness rules.
- Added automatic localized slug generation at entity creation, with editable manual slugs and collision-safe suffixing.
- Added predictable Ukrainian ASCII transliteration plus optional native UTF-8 slug mode.
- Added permanent 301 redirect history keyed to canonical route IDs, so repeated URL changes do not create redirect chains.
- Added reserved-route protection for admin/API/checkout/assets/system endpoints.
- Added one localized absolute-URL builder for internal links, canonical, hreflang, sitemap, Merchant and AI projections, including percent encoding for native UTF-8 paths.
- Added restart-safe `commerce:seo:backfill` for pre-0.9 localized catalog records.
- Added Ukraine-first fresh-store defaults: country UA, locale uk-UA, currency UAH and Europe/Kyiv timezone.
- Added mandatory seed records for uk-UA and UAH.
- Fixed schema-v3 inventory reservation mismatch by adding the `committed_at` timestamp used by the reservation state machine.
- Added `symfony/string` 8.1 as an explicit modern text dependency.
- Database schema version is now 4.

## 0.8.0 — 2026-09-16
- Normalized categories into shared catalog entities with separate store/market publication bridges.
- Added explicit market-level product/category availability for EU/UA channel control without catalog duplication.
- Added atomic DBAL stock reservations with idempotent reserve/release/commit transitions to prevent oversell under concurrent checkout.
- Added DB checks for price ranges and positive inventory quantities; reservation ownership is now mandatory.
- Historical database preference was superseded; the certified release baseline is MySQL 8.4 LTS and MariaDB 11.4 LTS.

- Reviewed database architecture against current Medusa, Saleor, Shopware and Adobe Commerce patterns.
- Added market/channel context so one storefront can serve Ukraine and multiple EU markets without catalog duplication.
- Normalized product options and variant option values; removed development `option_data` JSON from canonical variant storage.
- Added Inventory Item + variant-to-inventory mapping for bundles/kits/shared stock.
- Replaced direct variant stock table with stock levels per inventory item/location and first-class inventory reservations.
- Added price lists, market-aware prices, quantity upper bounds and extensible price rules.
- Added generic entity revisions outside storefront hot tables and immutable order event history.
- Added API idempotency storage for safe retry of checkout/payment/integration writes.
- Added namespaced extension metadata for non-query-critical custom data while keeping catalog filters in typed attribute tables.
- Removed development-era free-text product brand field; canonical brand relation is `brand_id`.
- Upgraded frontend compiler baseline to TypeScript 7.0.2 and vue-tsc 3.3.11 with explicit strict TypeScript 7 config.
- Database schema version is now 3.

## 0.7.0 — 2026-09-16

- Added explicit multilanguage registry using normalized BCP-47 locales and per-store locale activation/fallback metadata.
- Added explicit multicurrency registry using ISO-4217 codes, per-store currency activation, optional conversion policy and versioned exchange-rate observations.
- Added first-class Brand/Manufacturer entities instead of relying on a product text field.
- Added Google Product Category mapping at store-category level with per-product override and automatic-Google-classification fallback.
- Kept merchant-owned `product_type` separate from Google taxonomy.
- Added protected Migration Center system module and resumable migration tables.
- Added read-only OpenCart/ocStore 3.x catalog source for languages, currencies, manufacturers, categories and core product data.
- Added Universal Transfer Package v1 based on `manifest.json` + streaming NDJSON.
- Added safe ZIP package extraction with traversal and decompression limits; ZIP support remains optional through ext-zip.
- Added admin dashboard surfaces for Migration Center, Languages/Currencies and Google taxonomy.
- Database schema version is now 2.

## 0.6.0 — 2026-09-16

- First indexed commerce database schema, durable notifications, component inventory, signed updates and layered anti-abuse controls.

## 2026-09-25 — 3.3.1 hardening continuation
- Hardened RMA lifecycle with allowed server-side status transitions, detail view, event history, return tracking and mandatory resolution on completion.
- Completed saved carts workflow: 25-cart safety limit, rename, restore result accounting and ownership checks.
- Hardened product compare: product validation, stale entry pruning and clear-all action.
- Added Catalog Metadata Center for brand and attribute CRUD with localized brand SEO metadata, safe attribute types, filter/comparison flags and deletion guard when in use.

## 3.4.2 SOURCE CHECKPOINT 17

- Hardened production dependency materialization: added `bin/dependency-integrity-check.php` to reject missing/stale npm lock roots, incomplete Vite manifests, missing Composer runtime and accidentally shipped `node_modules`.
- Added `tools/bootstrap-production-online.sh` for verified Composer bootstrap, real lock generation, dependency audits, production Composer install, `npm ci`, TypeScript/Vue typecheck and Vite production build.
- Split network-independent final packaging into `tools/package-production.sh`; production archives can no longer be assembled merely by satisfying superficial file-presence checks.
- Added manual GitHub Actions production workflow that materializes dependencies, audits, compiles and publishes both the one-upload production ZIP and generated lock files as CI artifacts.
- No fabricated lock files, vendor tree or Vite build were committed when registry/Packagist access was unavailable in the audit environment.

## CHECKPOINT 19 — 2026-09-25

- Public API write contracts: API cart creation, idempotent variant quantity PUT and checkout from an API cart.
- API idempotency storage is now used by write flows; checkout also retains order-level checkout_idempotency_key protection.
- Added store-scoped outbound webhook subscriptions with encrypted secrets, event filters, idempotent creation, list/disable API and OpenAPI contracts.
- Added durable signed webhook delivery worker with HMAC SHA-256 signatures, SSRF URL policy, redirects disabled, bounded timeout, stale-lock recovery, retries and dead state.
- Added commerce:webhooks:work to the built-in scheduler.
- Public API readiness moved from foundation_incomplete to static_ready; runtime API/webhook E2E remains required before Stable.
- Hardened browser/CLI installation error boundaries so raw PDO/Throwable details are not shown to users.
- Hardened stale installer request cleanup by overwriting short-lived credential payload files before unlink.
- Added install-upgrade-safety-check.php, multistore-isolation-check.php, scheduler-queue-contract-check.php and outbound-webhook-check.php.
- All new user-facing text was added only to uk-UA. en-US, de-DE, da-DK, ru-RU and pl-PL storefront catalogs remain byte-identical to CHECKPOINT 18.
- Runtime/UI localization hardcode checks remain clean. A separate deeper audit identified 524 internal English literal exception diagnostics across 129 files; they are protected from HTTP exposure but remain localization debt under the stricter no-hardcoded-text policy.
