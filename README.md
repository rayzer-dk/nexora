# Nexora Commerce 3.5.1

3.5.1 використовує чистий Extension API 2.0 без legacy-сумісності Settings Schema v1; додає bonus extension packs, комерційні моделі розширень, покращений Theme Preset runtime та multilingual catalog import/export. Extension API 2.0: динамічні Builder-блоки, extension routes/pages, стабільні UI slots, extension permissions, scoped assets, власний журнал міграцій модулів і Trusted Signed Module Package з Ed25519-підписом. Непідписаний PHP/JS як і раніше не активується, а Core-файли не модифікуються.


Release channel: `production`. Repository CI certifies source/package contracts and the supported database matrix. Live deployment certification (HTTP, load, accessibility and configured external APIs) remains environment-specific and is performed separately.


3.3.9 adds transactional gift cards and store-scoped loyalty accounts with checkout redemption, paid-order accrual, idempotent rollback and customer/admin reward views.

Сучасна модульна e-commerce платформа для України та Європи. Сертифікований діапазон PHP: 8.4–8.5. Backend — Symfony 8.1, адміністративний frontend — Vue 3.5 + TypeScript 6, база — MySQL/MariaDB, InnoDB, utf8mb4.


## Локалізація

Поточна політика розробки: `uk-UA` є канонічною мовою для всіх нових інтерфейсних і runtime-повідомлень. Нові ключі додаються лише до українських каталогів. Наявні `en-US`, `de-DE`, `da-DK`, `ru-RU` та `pl-PL` не розширюються до окремого етапу перекладу; відсутні в них ключі мають безпечно використовувати український fallback. Користувацький текст не можна вбудовувати безпосередньо у PHP, Twig, JavaScript, TypeScript/Vue або bootstrap-код. Це контролює `php bin/localization-contract-check.php`.

## B2B commerce

3.3.9 adds store-scoped company accounts, buyer/approver/admin company roles, VAT/tax identifiers, credit limits, payment terms, company price lists, quantity-tier prices, purchase-order references and approval workflow. B2B pricing is revalidated at checkout and deferred-payment orders use the same inventory lifecycle as the standard checkout.

## Builder and navigation improvements in 3.3.6

The visual builder now supports reusable validated sections and one-click block duplication. Navigation editing uses a direct edit workflow and a safe parent selector, while server-side tree validation rejects cross-menu parents and cyclic nesting.

## За замовчуванням
- Цифрові товари: приватне сховище файлів поза public, права завантаження на конкретне замовлення, активація після оплати, ліміти/строк доступу та відкликання після повного refund.

Нова установка створює магазин для України:

- країна `UA`;
- мова `uk-UA`;
- валюта `UAH`;
- часовий пояс `Europe/Kyiv`;
- installer, базова адмінка, витрина та email — українською;
- системні URL — стабільні англійські (`/cart`, `/checkout`, `/shipping`, `/about-us` тощо).

## Встановлення

Основна українська інструкція: `docs/INSTALLATION.md`.

Правильна production-схема: один повний release ZIP → розпакувати → направити Document Root домену на `public/` → відкрити `/setup.php` → встановити. Після встановлення жодні файли переносити не потрібно.

Поточний архів розробки може не містити Composer `vendor/`. У такому випадку він є source-пакетом, а не one-upload production-release. `setup.php` показує це як окрему перевірку і не дозволяє виконати неповну установку.


## Презентаційне демо

Під час `/setup.php` можна залишити увімкненим прапорець встановлення демо. Альтернативно після інсталяції виконайте `php bin/console commerce:demo:install`. Видалення: `php bin/console commerce:demo:remove`. Деталі: `docs/DEMO_UK.md`.

## Основні принципи

- SSR-first витрина без jQuery і Bootstrap 3;
- мінімальний JavaScript за компонентами;
- критичні commerce-модулі захищені й поставляються із системою;
- додаткові розширення ізольовані та не змінюють Core;
- SEO URL, canonical, hreflang, structured data, sitemap і Merchant URL використовують один SEO Router;
- мультимовність і мультивалютність системні;
- fulfillment-driven checkout без універсальної адресної форми;
- Nova Poshta: повний shipment lifecycle для branch/parcel-locker (створення ТТН, скасування, tracking, optional label proxy). Укрпошта, Meest і Delivery Auto: location/provider transport та ручний shipment fallback до завершення їхніх окремих carrier shipment gateway;
- постійна корзина, резервування залишків на checkout, immutable order snapshots та idempotency;
- Google Commerce, Merchant API та AI-ready проєкції — системний інтеграційний шар;
- безпечні оновлення, backup/checkpoint і rollback є обов'язковою цільовою моделлю.

## Поточний статус

## Нова пошта API 2.0 3.3.3

Український профіль доставки `nova_post` використовує офіційну JSON-точку API 2.0. Пошук міст та відділень зберігає provider Ref, тому замовлення можна без повторного ручного введення перетворити на реальну ТТН. Для автоматичного створення необхідні API key та Ref відправника/контактної особи/міста/адреси у server environment. Секрети не потрапляють у HTML. Підтримано створення ТТН для відділення/поштомату, післяплату, перевірку tracking та підтверджене скасування у перевізника. Кур'єрська адреса залишається ручним fallback до додавання окремого Address Ref workflow.

## UX та адміністрування 3.3.3

- Товари й замовлення мають власні набори колонок і збережені представлення: адміністратор може зробити окремі робочі види для складу, бухгалтерії, підтримки або контенту.
- Додано швидкий preview товару й замовлення без виходу зі списку та втрати фільтрів/сторінки.
- Додано централізований журнал дій адміністраторів на базі `mc_audit_log`; записуються лише маршрут, дія, HTTP-статус та ідентифікатор об’єкта — без паролів, токенів і POST-вмісту.
- Visual Builder зберігає локальну аварійну копію незбережених змін у браузері та пропонує відновити її після випадкового закриття/перезавантаження сторінки.
- Виправлено store scope для адміністрування замовлень: список, preview, детальна картка та всі mutating actions перевіряють активний магазин на сервері.

Додано детальне керування адміністраторами: власні ролі, готовий режим «Тільки перегляд», права на перегляд/зміну/видалення/експорт/bulk/refund, окремий доступ до персональних даних і обмеження по магазинах. Недоступні розділи приховуються в меню, але головний захист завжди виконується сервером і працює за принципом deny-by-default.


- Єдиний Visual Store Editor із деревом секцій, preview та Desktop/Tablet/Mobile режимами.
- Готові visual presets і розширені Theme Tokens: кольори, радіуси, тіні, щільність, ширина контейнера та типографіка.
- Visual Merchandising категорій, Search & Recommendations, product boosts, related/complementary products.
- Spreadsheet Bulk Editor, Saved Views, quality indicators і actionable Dashboard.
- Email Preview, MP4/WebM у Media Library із progress upload, dynamic Product Builder sources.
- Один системний cron: `php bin/console commerce:cron:run`; Cron Center показує задачі, стан і рекомендований запуск кожні 5 хвилин.
- Окремий XSS regression gate: Twig autoescape + HtmlSanitizer для rich text + recursive Builder sanitization + перевірка небезпечних DOM sinks.
- Feed Center доповнено AI / Agentic Commerce JSONL.

3.5.1 — production build with CI-certified core runtime із Backup/Feed/Sitemap hardening та production commerce operations: promotion/coupon engine, bulk actions, CSV import/export, Notification Center, SMS gateway, double-opt-in newsletter/campaign queue та optional OpenAI/Gemini authoring. Попередні security/scale/asset hardening залишаються обов’язковою базою. Core має MIT-ліцензію та не вимагає платного runtime-компонента для базового self-hosted магазину. Додано dependency-license gate, tokenized design foundation, versioned Home/Header/Footer/Product builders із Safe Layout fallback, immutable `releases/current/shared` executor і failure-injection rollback tests, resumable Migration Center journal, ізольовані Google Commerce/Marketing sync queues, повноцінний Media Library admin UI, розширений visual Builder з draft/live preview/media picker, locale fallback та last-known-good currency-rate policy.

Це повна one-upload PRODUCTION-збірка з `vendor/`, compiled assets і lock-файлами, яка проходить статичні release/security/performance gates. Статус Stable/Production-certified ще потребує реальних load tests на MySQL/MariaDB, browser E2E на підтримуваній матриці та sandbox/E2E зовнішніх інтеграцій. Media Library і Builders реалізовані; Migration Center має повний основний OpenCart/ocStore import surface з dry-run/resume/mapping/rollback; Google Commerce залишається integration_pending до перевірки з реальним Merchant Center.




## Commerce UX / recovery 3.1.2

Додано restore backup із ПК з повною перевіркою архіву та страховочним snapshot, purchase states (у наявності / під замовлення / передзамовлення / очікується / продано / повідомити / ціна за запитом), double-opt-in stock alerts, Contact/Inquiry Center, реальне підключення published Product Builder до storefront, Checkout Builder з порядком/видимістю/обов’язковістю полів, AJAX-кошик із серверним перерахунком і групи покупців для promotion rules.

## Backup, feeds та sitemap 3.1.1

Додано Backup Center з профілями БД / дані магазину / повний backup, завантаженням архіву на ПК і безпечним restore даних із страховочною копією. Feed Center генерує атомарні snapshots для Google Merchant, Meta/Facebook, Pinterest, TikTok, Rozetka, Prom.ua, CSV та JSON через одну канонічну проєкцію товару/варіанта. Додані marketplace category mapping, `robots.txt`, sitemap index та окремі store/locale sitemap-файли. Великі фіди генеруються вручну або CLI/cron, а публічний URL лише віддає готовий snapshot.

## Commerce operations 3.1.0

- Серверний Promotion/Coupon Engine: автоматичні акції та промокоди, percent/fixed discounts, мінімальний subtotal, usage/per-customer limits, product/category scope, пріоритети та stop-processing. AJAX у checkout лише показує preview; остаточна знижка повторно обчислюється в транзакції створення замовлення.
- Каталог має CSV export та preview/apply import до 10 000 рядків за запуск. Створення/оновлення проходить через `ProductWriter`, тому не обходить validation, domain events, search/Google sync.
- Масова зміна статусу товарів (publish/draft/archive) також проходить через звичайний write pipeline; 200 товарів за один запуск.
- Notification Center показує email/Telegram/SMS outbox, retry/error state і дозволяє безпечний test enqueue. SMS — optional HTTPS gateway, OFF за замовчуванням, із SSRF policy та без redirects.
- Newsletter: double opt-in, signed unsubscribe і асинхронні email campaigns тільки для підтверджених активних підписників.
- AI authoring: optional OpenAI Responses API та Gemini generateContent. Ключі лише в environment; провайдери OFF за замовчуванням. AI вставляє чернетку в поля товару, але ніколи не зберігає її в обхід стандартної форми.

## Production-critical hardening 3.0.9

- Browser installer тепер записує повний набір runtime env-параметрів, потрібних Symfony container після чистої установки, включно з `DATABASE_SERVER_VERSION`, lock/cache defaults, Marketing Hub, Merchant API та Meilisearch OFF-defaults.
- Вимкнені Google Commerce і всі Marketing Hub providers мають fast-path: domain events не створюють зайві integration jobs.
- Integration queue отримала lease (`locked_at`/`locked_by`) і автоматичне відновлення jobs, якщо worker аварійно завершився після claim.
- `commerce:queues:status` виявляє dead/failed/stalled jobs і backlog старше 15 хвилин.
- `commerce:queues:purge --days=30` очищає лише успішно завершені старі записи; pending/retry/dead/failed та `delivered_with_failures` не видаляються.
- `php bin/production-critical-check.php` є автономним release gate для цих інваріантів.

## Asset Performance 3.0.7
Адміністративний CSS відокремлено від storefront CSS, а Builder/Media Library отримали окремі feature assets. Важкі UI-компоненти не повинні потрапляти в initial load. Production build виконує minification/tree-shaking/code splitting до релізу, а не під час HTTP-запиту. `npm run assets:audit` контролює Brotli-budget; `npm run build:production` збирає, перевіряє та precompress-ить production assets.

Campaign delivery fan-out is asynchronous. After creating a campaign, run the batch enqueue worker periodically:

`php bin/console commerce:campaigns:enqueue --batch=250 --max-batches=20`

The command only queues confirmed active subscribers into the durable notification outbox; the normal notification worker performs actual email delivery.

Detailed commerce operations notes: `docs/COMMERCE_OPERATIONS_3_1.md`.

Докладно про вибіркові права адміністратора: `docs/ADMIN_ACCESS.md`.

## Документи замовлення 3.3.3
Рахунок, пакувальний лист і credit note випускаються як незмінні snapshots з окремою нумерацією магазину/року. Доступні друк і PDF; покупець бачить лише власні invoice/credit note.

## Extension development

Third-party module and integration development is documented in `docs/DEVELOPER_GUIDE.md` and `docs/DEVELOPER_GUIDE_UK.md`. Public trusted provider contracts are documented in `docs/EXTENSION_PROVIDER_CONTRACTS.md`. Example extension sources are in `docs/examples/extensions/`.

Розширення 3.5.0 використовують тільки Extension API 2.0 і Settings Schema v2; pre-release сумісність зі schema v1 видалена. У `bonus/` входять необов’язкові приклади всіх трьох execution-моделей і три встановлювані теми. Вбудовані п’ять visual preset також отримали окрему геометрію, щільність і стиль. Universal Import Wizard підтримує багатомовні колонки виду `name[uk-UA]`, а multilingual CSV export виводить усі активні мови магазину в одному файлі.
