# Production readiness — Nexora Commerce 2.9.0

Статус цього документа: development build, 2026-09-17.

## 1. Installer

Реалізовано базовий production-oriented browser/CLI installer: PHP 8.4–8.5, обов'язкові PHP extensions, HTTPS, права запису, memory/disk recommendations, підключення MySQL/MariaDB, InnoDB, utf8mb4, strict SQL mode, migrations, admin/store/market seed, uk-UA/UAH/UA, optional demo, installation lock та післяінсталяційна health-перевірка.

Ще потрібні реальні clean-install/reinstall тести на цільових MySQL 8.4 і MariaDB 11.4 з повним vendor build.

## 2. Catalog CRUD

Реалізовано реальні create/edit/list для товарів і категорій, CSRF, пошук, ціна, SKU, GTIN/MPN, залишок, одиниці, категорії, бренд, SEO slug, статус, копіювання товару в draft та захищене видалення. Історичні товари замовлень фізично видалити не можна.

Ще потрібні повні UI/CRUD для multi-variant, attributes, documents, media/gallery, compare-at/promotions, tax override, Google Merchant fields, bulk actions та повноцінний Brand CRUD.

## 3. Media Engine

Реалізовано перевірку raster upload, оригінальний checksum, JPEG fallback, optional WebP/AVIF derivatives, responsive srcset, focal point, галерею товару та orphan cleanup. Несправний optional codec не блокує збереження. Ще потрібні повний Media Library/Image Manager, drag-and-drop, масові операції, variant media UI та окремий CDN/storage adapter.

## 4. Storefront

SSR storefront, responsive reference layout, category/product/search/blog/content/forum routes, presentation settings, live-search suggestions і product layout foundation уже є. Reference-візуал працює з реальними категоріями/товарами, а не лише з demo. Homepage blocks fault-isolated. Page/Header/Footer/Product/Checkout Builders і production visual editor реалізовані на рівні static-ready; до Stable потрібен browser E2E для authoring, revision, publish, rollback і responsive rendering.

## 5. Search / filters

DB search розширено: назва, короткий опис, SKU, GTIN, MPN, бренд і текстові характеристики; багатослівні запити розбиваються на bounded tokens, точні SKU/GTIN/MPN мають пріоритет. Додані фільтри за брендом, наявністю, діапазоном ціни та типізованими filterable-характеристиками, whitelist-сортування і responsive UI. У 2.8.0 додані керовані store/locale synonym groups; synonym-layer fail-soft і не є критичною залежністю пошуку. Вибір кількох значень одного атрибута має OR-семантику, різні атрибути комбінуються через AND. Nested query-параметри зберігаються при пагінації. Звичайні query-фільтри мають `noindex,follow`, щоб не створювати SEO-сміття.

Ще потрібні typo tolerance/fuzzy matching, морфологія/стемінг, адміністрування definitions/filterable policy на рівні Attribute Manager, окремі SEO-фільтри з канонічними landing URL та optional Meilisearch adapter для дуже великих каталогів.

## 6. Cart / Checkout

Робочий transactional slice уже присутній: cart, fulfillment selection, reservations, checkout та order creation. Повний production UX, guest identity/passkeys, усі edge cases перевізників та comprehensive E2E QA ще потрібні.

## 7. Payments

Provider contracts, offline methods, hosted mono acquiring, webhook/security foundation уже є. Потрібно завершити production certification, refunds/partial refunds по всіх providers та E2E idempotency tests.

## 8. Orders

Операційний Order Manager тепер працює: пошук і фільтри, детальна картка замовлення, immutable order items, керовані fulfillment transitions, tracking number, внутрішні нотатки, ручне підтвердження bank transfer/COD, завершення замовлення з інваріантами, повні та часткові refunds, журнал подій і повторне надсилання status-email. Помилка notification backend не відкатує успішну операцію замовлення.

Ще потрібні production documents/invoices, return merchandise workflow, carrier label generation, batch/bulk order actions і повний E2E coverage усіх статусних переходів.

## 9. Customers

Базовий customer account тепер працює: окремий storefront firewall, реєстрація/вхід/вихід, профіль, історія тільки власних замовлень, прив’язка нових авторизованих замовлень на сервері, wishlist, GDPR JSON export та контрольований erasure-request. Guest checkout залишається незалежним і не вимагає акаунта. Додані email verification/resend і безпечне password recovery/reset через одноразові hashed tokens з TTL. Ще потрібні passkeys/Google login, saved fulfillment preferences та повний review UX.

## 10. Google Commerce

Архітектурний модуль і частина foundation присутні. Повна Merchant API sync, diagnostics, notifications, promotions, inventory/feed lifecycle і admin issue center ще не production-ready.

## 11. Analytics / Marketing

Consent foundation є. Unified event layer, GA4/Ads/Meta/TikTok browser+server deduplication ще потрібно завершити.

## 12. Email / Telegram

Durable outbox, retry/backoff worker, email/Telegram senders, order-created і order-status responsive templates вже є. У Order Manager видно delivery status/attempts/error для пов’язаних повідомлень, а status-email можна повторно поставити в чергу. Збій SMTP/Telegram не повинен ламати checkout або admin order mutation.

Ще потрібні фінальні шаблони для shipment/refund/return/account lifecycle, глобальний notification center, bounce/delivery provider feedback і повний event coverage.

## 13. Migration Center

OpenCart/ocStore adapter реалізує languages, currencies, manufacturers, categories, attributes, products, images, options/values, specials, discounts, SEO URL, customers і orders, а також dry-run, mapping, resumable batches та run-scoped rollback. До Stable потрібна перевірка на кількох великих реальних OpenCart/ocStore базах. WooCommerce/Shopify/Shopware — окремі майбутні adapters.

## 14. Backup / Update / Recovery

Є signed-manifest/hash/preflight foundation, configuration revisions із checksum, rollback налаштувань, extension staging/quarantine/previous-version rollback, emergency isolation optional extensions, Last Known Good storefront і dependency-free fatal bootstrap fallback. Core Update перед live-змінами тепер виконує окрему перевірку staged release: структура, version metadata, PHP TOKEN_PARSE для всіх PHP-файлів, JSON/YAML та built storefront assets. Створений Recovery Point повторно перевіряється перед переключенням; якщо backup не підтверджено, live release не змінюється.

У 2.6.0 apply-процес уже включає verified Recovery Point, maintenance barrier, migration plan/run, atomic Core switch, одноразовий HTTP smoke probe через реальний entry point і rollback snapshot при помилці. Ще потрібні production deployment layout releases/current/shared, cache warmup у новому release context та реальні failure-injection тести на файловій системі/БД/HTTP для підтвердження автоматичного rollback.

## 15. Security

CSRF, sanitization, rate-limit, webhook security і базові security layers присутні. Потрібні повний OWASP ASVS review, dependency audit, CSP hardening, upload/SSRF tests, MFA/passkeys та audit coverage.

## 16. Performance

Архітектура допускає cache/Redis optional. Потрібні навантажувальні тести 10k/100k/500k products, SQL EXPLAIN/N+1 review, image benchmarks та checkout concurrency tests.

## 17. QA

Статичний PHP lint і consistency-перевірки виконуються для source build. Повний matrix QA PHP 8.4/8.5, MySQL/MariaDB, browsers, iOS/Android, languages/currencies, degraded external APIs та concurrent inventory ще не завершений.

## Найближчий порядок

1. Завершити production Media Engine.
2. Довести Catalog CRUD до variants/attributes/media/documents/bulk actions.
3. Пройти реальний вертикальний E2E: install -> product -> storefront -> cart -> delivery -> payment -> order -> notification -> admin order, включно з partial refund та failure injection.
4. Після цього: Migration Center, Google Commerce, analytics, Update/Backup/Recovery, security/performance matrix.

## 18. Site profiles / Forum

Профілі shop, catalog, content, landing, forum і hybrid перемикаються без видалення даних; окремі можливості можна вмикати пізніше. Додано базовий forum runtime/admin moderation. Повний community feature set (accounts, subscriptions, notifications, anti-abuse reputation, attachments) ще не завершений.

## Архітектурна стійкість 2.9.0

Критичні write-транзакції тепер використовують Transactional Outbox для domain events. Підписники мають окремий delivery ledger, bounded retries і dead-state, тому помилка додаткової реакції не відкочує вже валідне замовлення/платіж. Додані RBAC/store scope, Public API v1 foundation, architecture boundary tests та Extension SDK v1 із декларативними settings, шифруванням секретів і rollback revisions.

Повний immutable `releases/current/shared` executor із сертифікованими failure-injection сценаріями, повний security/performance audit і реальна E2E матриця все ще залишаються production blockers.
