# Модулі та інтеграції: посібник розробника

Цей посібник — один документ, з яким можна написати модуль для Nexora Commerce від заготовки до оновлення. Усе, що тут написано, перевірено на версії ядра 3.97.23 з Extension API 2.0: команди запускались, пакети встановлювались в адмінці на окремій копії магазину, а обмеження знайдено в коді, а не вигадано. Де щось незручно або не працює — так і сказано.

Чотири повні приклади лежать у `docs/examples/extensions/` (огляд: [README.md](examples/extensions/README.md)). Довідники по окремих темах: [EXTENSION_SDK.md](EXTENSION_SDK.md), [EXTENSION_SLOTS.md](EXTENSION_SLOTS.md), [EXTENSION_SETTINGS_SCHEMA.md](EXTENSION_SETTINGS_SCHEMA.md), [EXTENSION_PROVIDER_CONTRACTS.md](EXTENSION_PROVIDER_CONTRACTS.md).

## Три види модулів

Модуль — це ZIP з файлом `manifest.json` у корені. Що він може, визначає поле `execution`.

| Вид | Що це | PHP/JS у пакеті | Підпис |
| --- | --- | --- | --- |
| `declarative` | налаштування, переклади, блоки Конструктора, тексти у слотах, власні сторінки вітрини з текстом, CSS | ні | не потрібен |
| `remote_app` | паспорт зовнішнього сервісу (CRM, ERP, SaaS); сама логіка живе на вашому сервері й говорить з магазином через Public API і вебхуки | ні | не потрібен |
| `trusted_release` | PHP-код: обробники подій, cron-задачі, сторінки адмінки, провайдери оплати, доставки, ШІ, перекладу тощо; міграції БД; JS на вітрині | так | обов'язковий, Ed25519 |

Пакет з PHP без підпису не виконується: його поміщають у карантин. Ядро модуль не змінює ніколи — ні файлів, ні чужих таблиць.

## Шлях від заготовки до оновлення

Команди запускаються з кореня сайту. У прикладах нижче `php` — ваш PHP 8.4 (на Windows з Laragon це повний шлях до `php.exe`).

### 1. Заготовка

    php bin/console commerce:extension:scaffold acme.hello --name="Hello" --preset=basic

Вивід:

    Created extension scaffold: <сайт>/var/extension-scaffold/acme.hello

Код модуля має вигляд `виробник.назва`: малі латинські літери, цифри, `_`. Заготовки (`--preset`): `basic` (налаштування), `product-block` (блок товару), `remote-integration` (зовнішній сервіс), `trusted-route` (PHP-маршрут, потребує підпису), `theme` (тема). У каталозі з'являться `manifest.json`, `settings.schema.json`, `translations/uk-UA/messages.json`, `translations/en-US/messages.json` і `README.md`.

### 2. Правка

Змінюйте `manifest.json`, поля налаштувань, переклади. Для власного коду — файли в `src/` і `entrypoint` у маніфесті (див. приклад 4). Окремого режиму розробки немає: кожну зміну треба знову зібрати й завантажити, причому з новою `version` — той самий номер з іншим вмістом магазин відхилить, а точно такий самий ZIP просто проігнорує.

### 3. Збирання

    php bin/console commerce:extension:pack var/extension-scaffold/acme.hello --output=var/acme.hello.zip

Вивід:

    Package created and validated: var/acme.hello.zip

`pack` пропускає приховані файли й каталоги `vendor`, `node_modules`, `.git` і одразу перевіряє архів (без підпису).

### 4. Перевірка

    php bin/console commerce:extension:validate var/acme.hello.zip
    php bin/console commerce:extension:test var/acme.hello.zip

Вивід:

    VALID
    Code: acme.hello
    Version: 1.0.0
    Execution: declarative
    Files: 5
    PASS manifest
    PASS namespace/isolation
    PASS hot-disable
    PASS localization
    PASS settings-schema

`validate` — те саме, що робить магазин при завантаженні ZIP. `test` додатково перевіряє правила SDK (ізоляція, гаряче вимкнення, локалізація).

### 5. Підпис (лише trusted_release)

    php bin/console commerce:extension:keygen acme.2026 --out=/поза/репозиторієм/acme.2026.key
    php bin/console commerce:extension:sign var/acme.erp.zip /поза/репозиторієм/acme.2026.key

`keygen` створює секретний ключ і друкує дві речі: фрагмент `publisher`/`signature` для `manifest.json` і рядок з публічним ключем для файлу `var/config/trusted-publishers.json` кожного магазину, де модуль має працювати. Цей файл створюєте ви (через SSH або FTP); оновлення ядра його не чіпає. Без нього `validate` і встановлення відповідають «Видавець розширення не є довіреним». Секретний ключ ніколи не кладіть поруч з кодом модуля.

### 6. Встановлення в адмінці

Адмінка → Система → Розширення (`/admin/system/extensions`):

1. Оберіть ZIP і натисніть «Перевірити пакет». Відповідь: «Пакет перевірено та підготовлено. Він ще не активний, доки ви явно не натиснете «Активувати»».
2. Натисніть «Активувати» біля пакета. Відповідь: «Розширення активовано атомарно…». Міграції та файли готуються до перемикання; якщо щось падає, активною лишається попередня версія.
3. Якщо в модуля є налаштування, біля нього з'являється кнопка «Налаштування».

### 7. Оновлення

Зберіть ZIP з більшою `version` (наприклад 1.0.1), завантажте й активуйте так само. Попередня версія не видаляється: вона стає «disabled» і доступна для відкату. Налаштування при оновленні переносяться: нова версія, яку ще ніхто не налаштовував, отримує збережені значення найновішої нижчої версії (`ExtensionSettingsManager::carryOver`, запис `settings_carry_over` у журналі модуля). Значення перевіряються новою схемою: те, що вона вже не приймає (наприклад, варіант `select`, якого більше немає), замінюється її значенням за замовчуванням; нові поля отримують `default`; ключі, яких у новій схемі немає, зберігаються як були — і при наступних збереженнях форми теж. Перенесення відбувається один раз: власні зміни нової версії воно не перезаписує, а відкат на старішу версію нічого не копіює «вниз». Перевірено на копії магазину: модуль 1.0.0 з полями `multilingual_text`, `multilingual_textarea` і `text` → 1.1.0 — після активації форма 1.1.0 показала ті самі значення всіма мовами, нове поле — зі значенням за замовчуванням.

### 8. Відкат, вимкнення, видалення

- «Відкотити» біля активної версії повертає попередню перевірену версію (з її налаштуваннями). Відповідь: «Відновлено попередню перевірену версію example.hello_settings 1.0.0…».
- «Вимкнути» зупиняє модуль одразу; дані лишаються.
- «Видалити» доступне лише для неактивної версії; позначка «З даними модуля» додатково запускає `down`-міграції. Деталі: [EXTENSION_LIFECYCLE.md](EXTENSION_LIFECYCLE.md).

## Приклад 1. Налаштування модуля

Каталог `docs/examples/extensions/hello-settings`: `manifest.json` (30 рядків), `settings.schema.json` (шість полів), дві локалізації. PHP немає.

Ключове в маніфесті:

```json
"execution": "declarative",
"settings_schema": "settings.schema.json",
"localization": {"default_locale": "uk-UA", "locales": ["uk-UA", "en-US"], "translations_path": "translations"}
```

Поле налаштування:

```json
{"key": "accent", "type": "color", "label": "Акцентний колір", "default": "#0057d9", "group": "design", "help": "Формат #RRGGBB."}
```

Після активації кнопка «Налаштування» відкриває форму з полями «Увімкнено», «Заголовок», «Внутрішня нотатка», «Акцентний колір», «Кількість колонок», «Ключ API» (секретне поле зберігається зашифрованим). Кожне збереження — ревізія, до якої можна повернутися.

Поля `multilingual_text` і `multilingual_textarea` показують окреме поле на кожну мову, увімкнену в магазині (мова за замовчуванням — першою), і зберігають значення як об'єкт `{"uk-UA": "…", "en-US": "…"}`; порожні мови не зберігаються, `required` вимагає хоча б одну.

Trusted-модуль читає свої налаштування з контексту, який отримує в `boot()`:

```php
$title = (string) $context->setting('headline', '');   // одне поле; другий аргумент — якщо значення немає
$all = $context->settings();                            // усі поля: збережене, інакше default зі схеми
$greeting = $all['greeting']['uk-UA'] ?? '';             // багатомовне поле — масив за мовами
```

Значення читаються в момент виклику, тож обробник події чи cron-задача бачать останнє збереження без перезапуску; секретні поля приходять розшифрованими.

Що не так: декларативний модуль (без PHP) своїх налаштувань прочитати не може — ні Twig-функції, ні змінної в слотах немає, тож для нього форма лишається формою з ревізіями. Підписи полів не перекладаються (пишіть мовою адмінки).

## Приклад 2. Блок на сторінці товару

Каталог `docs/examples/extensions/product-badge`. Модуль оголошує компонент Конструктора та внесок у слот:

```json
"capabilities": ["catalog.product.block"],
"ui_slots": ["product.after_price"],
"blocks": [{"id": "extension.example_product_badge.badge", "surface": "product", "regions": ["below_primary", "hero_summary"], "label_key": "extension.example_product_badge.badge.label", "settings_schema": "blocks/badge.schema.json", "allow_multiple": false}],
"slot_contributions": [{"slot": "product.after_price", "component": "extension.example_product_badge.badge", "priority": 20, "title_key": "extension.example_product_badge.slot.title", "content_key": "extension.example_product_badge.slot.text"}]
```

Перевірено на вітрині: на сторінці товару після ціни з'являється «Спеціальна пропозиція» з текстом з `translations/uk-UA/messages.json`. У Оформлення → Конструктор → Товар у списку «Додати блок» є «Позначка товару»; заголовок і текст блоку вводяться в звичайних полях Конструктора.

Що не так: і слот, і блок рендерить один шаблон ядра `themes/default/templates/product/blocks/extension_component.html.twig`, який показує лише заголовок і текст. Власної розмітки, кнопок, стилю, даних товару (ціна, наявність) там немає. `blocks/badge.schema.json` валідатор перевіряє, але Конструктор форму з нього не будує. Регіони блоку — лише `hero_media`, `hero_summary`, `below_primary`, `below_secondary`, `mobile_sticky`.

## Приклад 3. Віддалена інтеграція з вебхуком замовлення

Два каталоги: `docs/examples/extensions/remote-crm` (паспорт модуля) і `docs/examples/extensions/remote-crm-receiver` (файл `receiver.php` для сервера CRM).

Паспорт:

```json
"execution": "remote_app",
"capabilities": ["integration.crm"],
"remote": {"base_url": "https://crm.example.com", "health_path": "/health", "webhook_path": "/webhooks/nexora"}
```

Чесно про паспорт: магазин перевіряє лише, що `remote.base_url` — публічна HTTPS-адреса. `health_path` і `webhook_path` сьогодні ніхто не викликає, і сам модуль замовлення не доставляє. Доставку робить вебхук Public API, який підключається так:

1. Адмінка → Система → API-ключі (`/admin/system/integrations/api`): створіть токен з правом `webhooks:manage` (і `orders:read`, якщо CRM має забирати повне замовлення).
2. Створіть підписку (один раз, з будь-якої машини):

```
curl -X POST https://ваш-магазин/api/v1/webhooks \
  -H "Authorization: Bearer <токен>" \
  -H "Idempotency-Key: crm-orders-2026-10-14-0001" \
  -H "Content-Type: application/json" \
  -d '{"name": "CRM", "target_url": "https://crm.example.com/webhooks/nexora", "events": ["commerce.order.placed"]}'
```

У відповіді 201 є `secret` — він показується лише тут. Покладіть його в змінну середовища `NEXORA_WEBHOOK_SECRET` на сервері CRM.

3. Розмістіть `receiver.php` за HTTPS. Кожна доставка приходить з заголовками `X-Nexora-Event`, `X-Nexora-Delivery`, `X-Nexora-Timestamp` і `X-Nexora-Signature: sha256=<hex>`, де підпис — HMAC-SHA256 від рядка `timestamp + "." + тіло` з вашим секретом. Тіло: `{"id", "type", "version", "aggregate": {"type", "id"}, "data", "occurred_at"}`; для `commerce.order.placed` у `data` є `order_number`, `store_id`, `market_id`, `currency`, `total_minor`, а `aggregate.id` — публічний id замовлення для `GET /api/v1/orders/{id}`.
4. `receiver.php` відкидає неправильний підпис і запити старші за 300 секунд (401), пропускає повтор того самого `id` події (200 «duplicate») і передає замовлення далі (у прикладі — рядок у JSONL-файлі; замініть функцію `forwardOrderToCrm`).

Магазин повторює доставку, доки не отримає 2xx: до 8 спроб з паузою від 15 секунд до 1 години. Підписку можна створити на `commerce.order.placed`, `commerce.order.cancelled`, `commerce.order.completed`, `commerce.payment.status_changed`, `commerce.catalog.product_created`, `commerce.catalog.product_updated`, `commerce.customer.registered`. Події замовлення й оплати несуть у `data` поле `store_id`, і доставляються лише підпискам магазину цього замовлення; для `commerce.order.cancelled` у `data` ще є `reason` (`admin_cancel` або `payment_expired`), для `commerce.payment.status_changed` — `status`, `provider` і (від платіжного сервісу) `provider_status`.

Що не так: екрана для вебхуків в адмінці немає — лише `GET`, `POST` і `DELETE` на `/api/v1/webhooks`. Вбудовані «Підключення замовлень» (Система → Підключення замовлень) підписують запити інакше (HMAC лише від тіла, без часу), тож `receiver.php` для них не підходить.

## Приклад 4. Trusted PHP-модуль: подія, cron, сторінка адмінки

Каталог `docs/examples/extensions/trusted-order-hook`: `manifest.json`, `docs/examples/extensions/trusted-order-hook/src/Entrypoint.php`, `docs/examples/extensions/trusted-order-hook/src/OrderLog.php`, переклади. Що він робить: записує кожне нове замовлення в журнал, раз на годину рахує нові замовлення й показує журнал на сторінці адмінки.

Усе, що модуль використовує, оголошується в маніфесті — неоголошене контекст відхилить:

```json
"type": "trusted-module",
"execution": "trusted_release",
"permissions": ["extension.example_order_hook.view"],
"events": ["commerce.order.placed"],
"routes": [{"name": "extension.example_order_hook.log", "path": "/admin/extensions/example_order_hook/log", "methods": ["GET"], "mode": "trusted_handler", "permission": "extension.example_order_hook.view"}],
"scheduled_tasks": [{"code": "digest", "interval": 3600, "label": "Order hook digest", "description": "Counts the orders the module has logged since the previous run."}],
"publisher": {"name": "Example Publisher", "key_id": "example.docs.2026"},
"signature": {"algorithm": "ed25519", "key_id": "example.docs.2026", "file": "SIGNATURE.ed25519"},
"autoload": {"psr4": {"Example\\OrderHook\\": "src"}},
"entrypoint": "Example\\OrderHook\\Entrypoint"
```

Точка входу реалізує `TrustedExtensionEntrypointInterface` і в `boot()` реєструє обробники через `TrustedExtensionContext` (`src/Core/Extension/TrustedExtensionContext.php`):

```php
$context->on('commerce.order.placed', static function (StoredDomainEvent $event) use ($log): void {
    $log->append($event->eventId, ['order_number' => (string) ($event->payload['order_number'] ?? '')]);
});
$context->scheduledTask('digest', static fn (): string => $log->digest());
$context->route('extension.example_order_hook.log', static fn (Request $request, array $route): Response => new Response('...'));
```

Збирання, підпис, перевірка (вивід з реального запуску):

    php bin/console commerce:extension:pack docs/examples/extensions/trusted-order-hook --output=var/order-hook.zip
    Package created and validated: var/order-hook.zip
    Trusted package is not signed yet: run commerce:extension:sign before installing it.
    php bin/console commerce:extension:validate var/order-hook.zip
    INVALID: Файл підпису довіреного розширення відсутній.
    php bin/console commerce:extension:sign var/order-hook.zip /поза/репозиторієм/example.docs.2026.key
    Signed: var/order-hook.zip
    php bin/console commerce:extension:validate var/order-hook.zip
    VALID
    Code: example.order_hook
    Version: 1.0.0
    Execution: trusted_release
    Files: 7

Що побачено після встановлення й активації на копії магазину:

- нове замовлення (подія `commerce.order.placed`) потрапило в журнал: `commerce:events:work` вивів `claimed=1 delivered=1 partial=0 dead=0`;
- сторінка `/admin/extensions/example_order_hook/log` відповіла 200 і показала рядок `DOCS-173556 UAH 1234.50`;
- у Система → Cron / Автоматизація з'явилась задача «Order hook digest» (`extension.example_order_hook.digest`), ручний запуск `php bin/console commerce:cron:run --task=extension.example_order_hook.digest` вивів `OK · New orders: 1 (UAH 1234.50)`;
- після оновлення 1.0.0 → 1.0.1 журнал зберіг старі записи (дані лежать поза каталогом версії);
- право `extension.example_order_hook.view` з'явилось у Система → Адміністратори та права в групі «Розширення».

Що не так: контекст trusted-модуля дає налаштування модуля (`$context->settings()`, див. приклад 1), але не дає ні з'єднання з БД, ні сховища, ні журналу, ні HTTP-клієнта. Тому приклад пише дані у файл `var/extension-data/example.order_hook/` (поза каталогом версії, щоб дані пережили оновлення). Власні таблиці можна створити міграцією (`migrations/*.php` повертає `['up' => fn (Connection $db) => …, 'down' => …]`), але читати їх у робочому коді модулю нічим. Сторінка адмінки — це сирий `Response`: без меню, шапки й стилів адмінки, і пункт у меню модуль додати не може. Обробник події викликається вже після збереження замовлення (одразу після відповіді покупцю або наступним проходом cron-задачі «events», кожні 5 хвилин) і не може змінити чи скасувати замовлення.

## Шпаргалка

### Маніфест

| Поле | Що означає |
| --- | --- |
| `code`, `name`, `version`, `type`, `core`, `extension_api`, `permissions` | обов'язкові; `version` у форматі 1.2.3, `type`: `app`, `trusted-module` або `theme`, `core` — версія ядра (формат під таблицею), `extension_api` — `2.0` |
| `execution` | `declarative` (типово), `remote_app`, `trusted_release` |
| `isolation`, `hot_disable` | мають бути `contract_only` і `true`, інакше `commerce:extension:test` покаже FAIL |
| `localization` | `default_locale` лише `uk-UA`; файли `translations/<мова>/*.json` для кожної мови з `locales` |
| `settings_schema` | шлях до схеми налаштувань у пакеті |
| `blocks`, `ui_slots`, `slot_contributions`, `pages`, `routes`, `assets` | що модуль додає в інтерфейс |
| `events`, `scheduled_tasks`, `capabilities`, `migrations`, `autoload`, `entrypoint`, `publisher`, `signature` | лише для `trusted_release` (крім `capabilities` без префікса `provider.`) |
| `remote` | для `remote_app`: `base_url` публічна HTTPS-адреса |
| `commercial` | `model`: `free`, `one_time`, `subscription`, `external` |

Схема маніфесту: `extensions/manifest.schema.json`. Усі ідентифікатори (блоки, маршрути, права, ключі перекладів, сторінки) мають починатися з `extension.<код з _ замість .>.`, наприклад `extension.acme_hello.`.

Поле `core` — обмеження в стилі Composer: `^3.4.8`, `~3.4.8`, `>=3.0.0`, точна `3.4.8` або кілька гілок через «або», наприклад `"core": "^3.4.8 || ^4.0"`. Пакет приймається, якщо поточне ядро входить хоча б в одну гілку; `^3.4.8` без гілки `^4.0` на ядрі 4.x відхиляється, а одна хибна гілка робить невалідним усе обмеження.

### Налаштування

Схема `{"schema_version": 2, "fields": [...]}`. Типи: `text`, `textarea`, `number`, `integer`, `boolean`, `select`, `url` (лише https), `email`, `secret`, `color` (#RRGGBB), `range`, `multilingual_text`, `multilingual_textarea` (два останні — поле на кожну мову магазину, значення — об'єкт за кодами мов). Властивості поля: `key`, `type`, `label`, `help`, `required`, `default`, `min`, `max`, `step`, `options`, `group`, `placeholder`, `advanced`, `help_url`, `example`. Деталі: [EXTENSION_SETTINGS_SCHEMA.md](EXTENSION_SETTINGS_SCHEMA.md).

### Слоти

`php bin/console commerce:extension:list-points` друкує 36 назв. Шаблони теми за замовчуванням реально виводять 29: усі `product.*`, `category.before_grid`, `category.after_grid`, `cart.before_summary`, `cart.after_summary`, `account.dashboard`, усі `checkout.*`, `admin.product.after_main`, `admin.category.after_main`, `admin.order.sidebar`, `admin.dashboard`, `admin.extensions.toolbar`. Слоти `admin.product.sidebar`, `admin.order.actions`, `admin.customer.sidebar`, `navigation.items`, `content.page` і застарілі `storefront.product.after_price`, `storefront.checkout.before_payment` можна оголосити, але на сторінці їх не видно. Внесок у слот — це заголовок і текст з перекладів (`title_key`, `content_key`), більше нічого. Таблиця: [EXTENSION_SLOTS.md](EXTENSION_SLOTS.md).

### Події

`php bin/console commerce:extension:list-events` друкує 10 подій, які ядро справді публікує, — саме ті, що в таблиці нижче. З `--all` команда додає ще 21 зарезервовану назву з позначкою `(reserved: not published by the core, a handler never runs)`: підписатися на них маніфест дозволяє, але обробник не спрацює. Відповідність команди, коду ядра й цієї таблиці перевіряє тест `tests/Unit/ExtensionListEventsTest.php`.

| Подія | Коли | Дані (`payload`) |
| --- | --- | --- |
| `commerce.order.placed` | оформлено замовлення | `order_number`, `store_id`, `market_id`, `currency`, `total_minor` |
| `commerce.order.cancelled` | замовлення скасовано адміном або не оплачено вчасно | `store_id`, `reason`: `admin_cancel` або `payment_expired` |
| `commerce.order.completed` | адмін позначив замовлення виконаним | `store_id` (замовлення — в `aggregateId`) |
| `order.shipped` | адмін вказав трек-номер | `store_id`, `tracking_number` |
| `commerce.payment.status_changed` | змінився стан оплати | `store_id`, `status`, `provider`, `provider_status` |
| `commerce.customer.registered` | реєстрація покупця | `locale` |
| `commerce.catalog.product_created` | створено товар | `store_id`, `market_id`, `status` |
| `commerce.catalog.product_updated` | змінено товар (зокрема переклад) | `store_id`, `market_id`, `status` |
| `commerce.catalog.category_created` | створено категорію | `store_id`, `market_id` |
| `commerce.catalog.category_updated` | змінено категорію | `store_id`, `market_id` |

Не публікуються: `checkout.*`, `product.loaded`, `product.price.resolved`, `product.stock.resolved`, `product.before_save`, `product.saved`, `product.deleted`, `order.created`, `order.status.changed`, `order.refunded`, `customer.created`, `customer.updated`, `customer.deleted`, `content.page.rendering`, `layout.blocks.collect`, `navigation.items.collect`. Схеми даних: `resources/event-contracts/v1/`. Обробник отримує `StoredDomainEvent` (`src/Core/Event/StoredDomainEvent.php`) з полями `eventId`, `eventName`, `aggregateType`, `aggregateId`, `payload`, `metadata`, `occurredAt`. Одна подія може прийти двічі — робіть обробник ідемпотентним за `eventId`.

### Провайдери

`php bin/console commerce:extension:list-contracts`. Провайдер реєструється в `boot()` і вимагає відповідний рядок у `capabilities` та `trusted_release`.

| Capability | Метод контексту | Інтерфейс |
| --- | --- | --- |
| `provider.payment` | `paymentProvider()` | `src/Modules/Payment/Contract/PaymentProviderInterface.php` |
| `provider.shipping` | `deliveryProvider()` | `src/Modules/Shipping/Contract/DeliveryProviderInterface.php` |
| `provider.product_block` | `productBlockProvider()` | `src/Modules/ProductPage/Contract/ProductBlockProviderInterface.php` |
| `provider.ai` | `aiProvider()` | `src/Modules/Ai/Contract/TextGenerationProviderInterface.php` |
| `provider.translation` | `translationProvider()` | `src/Modules/Ai/Contract/TranslationProviderInterface.php` |
| `provider.exchange_rate` | `provide()` | `src/Modules/Pricing/Contract/ReferenceRateSourceInterface.php` |
| `provider.search` | `provide()` | `src/Modules/Search/Contract/SearchCandidateProviderInterface.php` |
| `provider.notification_sender` | `provide()` | `src/Modules/Notification/Contract/NotificationSenderInterface.php` |
| `provider.feed` | `provide()` | `src/Modules/Feeds/Contract/FeedFormatProviderInterface.php` |

Приклади коду провайдерів: [EXTENSION_PROVIDER_CONTRACTS.md](EXTENSION_PROVIDER_CONTRACTS.md).

### Cron

`scheduled_tasks` — лише в `trusted_release`, до 16 задач. `code`: латиниця, цифри, `_`, до 40 символів; `interval`: від 300 до 604800 секунд; `label` обов'язковий (до 120 символів), `description` до 300. Обробник: `$context->scheduledTask('<code>', fn (): ?string => …)`, рядок, який він поверне, видно в журналі задачі. Задача з'являється в Система → Cron під назвою `extension.<код з _>.<code>`; запуск вручну: `php bin/console commerce:cron:run --task=extension.acme_hello.sync`.

### Маршрути

- Адмінка: шлях лише `/admin/extensions/<код з _>/...`, `mode: trusted_handler`, обов'язкове `permission` з `permissions` модуля. Без ролі супер-адміна доступ дає лише явне право в Система → Адміністратори та права.
- Вітрина: будь-який вільний шлях (не `/setup`); `mode: page` показує декларативну сторінку з `pages` (заголовок і текст з перекладів), `mode: trusted_handler` викликає ваш обробник.
- Обробник: `fn (Request $request, array $route): Response`; він має повернути `Symfony\Component\HttpFoundation\Response`, інакше запит падає.

### Переклади

Файли `translations/<мова>/messages.json` — плаский JSON «ключ: текст». `uk-UA` обов'язкова й типова, ключі лише з префіксом `extension.<код з _>.`. Ядро бере тексти модуля для `label_key`, `title_key`, `content_key`; назва модуля — ключ `extension.<код з _>.name`.

### Ресурси

`assets`: `{"path": "assets/site.css", "type": "css", "scopes": ["storefront.global"]}`. CSS дозволено всім модулям (без `url()` на зовнішні адреси та `@import` ззовні), JS — лише `trusted_release`. Області: `storefront.global` або `route:<назва маршруту>`. В адмінці ресурси модулів не підключаються.

### Що можна класти в ZIP

Безпечні файли: `json`, `css`, `png`, `jpg`, `jpeg`, `webp`, `avif`, `gif`, `txt`, `md`, `woff`, `woff2`, `ed25519`. Виконувані `php`, `phtml`, `js`, `mjs`, `cjs`, `twig` — лише з підписом (Twig — тільки теми й тільки `templates/` вітрини). Заборонено завжди: `phar`, `sh`, `exe`, `dll`, `bat`, `ps1`, `py` тощо, приховані файли, символьні посилання. До 50 МБ архів, до 5000 файлів.

## Налагодження

- Журнал життєвого циклу — Система → Розширення, блок «Журнал модулів»: встановлення, активації, відкати, помилки.
- Якщо trusted-модуль падає під час запуску, магазин сам вимикає лише його і записує текст помилки (`last_error`) — модуль стає «disabled», ядро працює далі. Загальні помилки: Система → Стабільність.
- Події: `php bin/console commerce:events:status` (стан черги) і `php bin/console commerce:events:work` (обробити зараз).
- Cron-задача модуля: `php bin/console commerce:cron:run --task=extension.<код з _>.<code>`.
- Перед завантаженням завжди запускайте `validate` і `test`: магазин відхилить пакет з тим самим текстом.

Типові відповіді валідатора:

| Повідомлення | Що виправити |
| --- | --- |
| У manifest.json бракує обов'язкового поля | додайте `code`, `name`, `version`, `type`, `core`, `extension_api`, `permissions` |
| Поле version має бути у форматі 1.2.3 | без `v` і суфіксів |
| Пакет зроблений для іншої версії API розширень | `extension_api` має бути `2.0` |
| Пакет не підтримує цю версію ядра | поправте `core`, додайте гілку поточної мажорної версії ядра (формат під таблицею маніфесту) |
| Для розширення default_locale має бути uk-UA | українська — обов'язкова типова мова |
| Відсутній файл локалізації для locale | кожна мова з `locales` потребує файлу в `translations/<мова>/` |
| manifest.json вказує на файл, якого немає в архіві | перевірте `settings_schema` блоку або модуля |
| slot_contributions використовує slot, який не оголошено в ui_slots | додайте слот у `ui_slots` |
| Внесок розширення у слот посилається на необоголошений блок | `component` має збігатися з `id` блоку з `blocks` |
| Дозволи розширення мають використовувати простір імен | права лише `extension.<код з _>.*` |
| Адмін-маршрути розширення потребують оголошеного дозволу | додайте `permission`, яке є в `permissions` |
| Шлях маршруту розширення недійсний або зарезервований | адмінка лише `/admin/extensions/<код з _>/` |
| scheduled_tasks — до 16 задач розширення trusted_release… | інтервал 300–604800, `label` не порожній, `execution: trusted_release` |
| Provider capabilities дозволені лише для execution=trusted_release | провайдер потребує підписаного пакета |
| В архіві є файл з недозволеним розширенням | приберіть скрипти й бінарні файли |
| Файл підпису довіреного розширення відсутній | запустіть `commerce:extension:sign` |
| Видавець розширення не є довіреним | додайте публічний ключ у `var/config/trusted-publishers.json` магазину |
| Не вдалося перевірити підпис довіреного розширення | ZIP змінено після підпису або не той ключ: зберіть і підпишіть знову |
| Trusted-розширення намагалося підписатися на неоголошену подію | додайте подію в `events` (це помилка запуску, не валідатора) |

## Що можна змінити модулем, а що — ні

| Точка розширення | Вид модуля | Що можна | Контракт у коді |
| --- | --- | --- | --- |
| Налаштування модуля | усі | форма з полями й ревізіями в адмінці; значення модулю не віддаються | `src/Core/Extension/ExtensionSettingsManager.php` |
| Слоти | усі | заголовок і текст з перекладів у 29 місцях вітрини й адмінки | `src/Core/Extension/ExtensionPoint.php` |
| Блоки Конструктора | усі | блок з заголовком і текстом на сторінці товару (і оформлення замовлення) | `src/Modules/ProductPage/Application/ExtensionProductBlockProvider.php` |
| Сторінки вітрини | усі | сторінка з заголовком і текстом за власним шляхом | `src/Core/Extension/ExtensionRouteController.php` |
| CSS вітрини | усі | власні стилі глобально або для маршруту | `src/Core/Extension/ExtensionAssetTwigExtension.php` |
| Теми | declarative або trusted | CSS, шрифти, зображення; підписана тема — ще й Twig-шаблони вітрини | `src/Core/Extension/Theme` |
| Події | trusted | реакція на 10 опублікованих подій після збереження | `src/Core/Extension/TrustedExtensionDomainEventSubscriber.php` |
| Вебхуки Public API | remote | події замовлення, товарів і реєстрації на ваш HTTPS-сервер з підписом (див. обмеження в прикладі 3) | `src/Modules/Api/Webhook/ApiWebhookDeliveryWorker.php` |
| Public API | remote | читати каталог, клієнтів, замовлення; змінювати залишки, замовлення, кошики | `src/Modules/Api/Http/PublicApiV1Controller.php` |
| Cron-задачі | trusted | власна періодична задача від 5 хвилин | `src/Core/Extension/TrustedExtensionRuntimeRegistry.php` |
| Маршрути адмінки | trusted | власна сторінка під `/admin/extensions/<код>/` з власним правом | `src/Core/Extension/AdminExtensionRouteController.php` |
| Провайдери оплати | trusted | новий спосіб оплати | `src/Modules/Payment/Contract/PaymentProviderInterface.php` |
| Провайдери доставки | trusted | новий перевізник | `src/Modules/Shipping/Contract/DeliveryProviderInterface.php` |
| Провайдери ШІ та перекладу | trusted | власна модель для генерації або перекладу | `src/Modules/Ai/Contract/TextGenerationProviderInterface.php` |
| Курси, пошук, розсилки, фіди | trusted | джерело курсів, кандидати пошуку, канал повідомлень, формат фіду | `src/Core/Extension/ExtensionServiceRegistry.php` |
| Міграції БД | trusted | власні таблиці з `up` і `down` | `src/Core/Extension/ExtensionMigrationRunner.php` |
| JS вітрини | trusted | власний скрипт глобально або для маршруту | `src/Core/Extension/ExtensionPackageValidator.php` |

Чого модулем змінити не можна:

- ціну, наявність, склад кошика чи замовлення в момент розрахунку — синхронних хуків немає, події лише повідомляють про вже збережене;
- поведінку оформлення замовлення: додати поле, перевірку, заборону (лише свій спосіб оплати чи доставки як провайдер);
- шаблони, меню й сторінки адмінки ядра (власна сторінка модуля — окремий сирий `Response` без меню);
- шаблони листів і документів замовлення (`email`, `order_document`) — навіть підписана тема їх не перевизначає;
- будь-які файли ядра і чужі таблиці;
- вбудовані сервіси ядра: контекст trusted-модуля дає лише налаштування модуля, але не БД, кеш, журнал, HTTP-клієнт чи чергу;
- права й ролі ядра (модуль лише додає свої `extension.<код>.*`);
- вивід слота або блоку: розмітка завжди з шаблону ядра, лише заголовок і текст.

## Наскільки це просто

Коротко: простий декларативний модуль — справа пів години, якщо вам достатньо форми налаштувань або тексту на сторінці. Модуль з власною логікою реально написати за день, але частину того, що зазвичай дає фреймворк (БД, журнал), доведеться робити самому; налаштування модуль читає з контексту.

Що перевірено запуском 14.10.2026 на окремій копії магазину (версія 3.97.23):

- `scaffold` → `pack` → `validate` → `test` для заготовки `acme.hello` — усе PASS (вивід — у розділі «Шлях від заготовки до оновлення»);
- `pack` і `validate` для всіх чотирьох прикладів: VALID (`hello-settings` 5 файлів, `product-badge` 5, `remote-crm` 4, підписаний `trusted-order-hook` 7);
- встановлення й активація всіх чотирьох через Система → Розширення;
- налаштування `hello-settings`: збереження, ревізія, оновлення до 1.0.1 і відкат до 1.0.0;
- 17.10.2026: модуль з полями `multilingual_text`/`multilingual_textarea` — значення за мовами збережено, після перезавантаження на місці, після оновлення 1.0.0 → 1.1.0 перенесено в нову версію;
- слот `product-badge` на сторінці товару й блок у списку Конструктора;
- trusted-модуль: подія замовлення, сторінка адмінки (200), cron-задача, право в ролях;
- `receiver.php` і всі приклади перевіряє автоматичний тест `tests/Unit/DeveloperExamplesTest.php`: пакування, підпис свіжим ключем, подія, задача, сторінка, підпис вебхука, повтори.

Розмір мінімального модуля: заготовка `basic` — 5 файлів і 57 рядків (без `README.md` — 4 файли, 48 рядків), проходить і `validate`, і `test`. Магазин прийме навіть один `manifest.json` на 9 рядків з сімома обов'язковими полями, але `test` покаже три FAIL (ізоляція, гаряче вимкнення, локалізація). Trusted-приклад з подією, cron і сторінкою — 5 файлів без README, 212 рядків, з них PHP — 144 (два класи).

Реальні незручності, як вони є:

1. Декларативний модуль не може прочитати свої налаштування: немає Twig-функції чи змінної в слотах (trusted-модуль читає їх через `$context->settings()`).
2. Підписи полів налаштувань не перекладаються — одна мова для всіх адміністраторів.
3. Слоти й блоки показують лише заголовок і текст: ні HTML, ні кнопки, ні даних товару. Схема налаштувань блоку Конструктором не використовується.
4. Підписка на зарезервовані події (`order.created`, `product.price.resolved` та ін., див. `list-events --all`) маніфестом дозволена, але обробник не спрацює.
5. Немає синхронних хуків: змінити ціну, кошик, перевірку оформлення замовлення модулем неможливо.
6. Trusted-модуль не отримує БД, журналу, HTTP-клієнта; дані доводиться тримати у файлах `var/`.
7. Сторінка адмінки модуля — голий HTML без меню й стилів адмінки, пункт меню додати не можна.
8. Remote-інтеграція: блок `remote` у маніфесті нічого не вмикає, вебхук треба створювати вручну через `curl`, екрана для вебхуків немає.
9. Підпис: ключ генерується в консолі, а публічний ключ на кожен магазин кладеться вручну у `var/config/trusted-publishers.json`.
10. Немає режиму розробки: кожна зміна — новий номер версії, збирання, завантаження, активація; той самий номер з іншим вмістом відхиляється.
11. JS модуля підключається лише на вітрині й лише в trusted-пакеті; в адмінку ресурси модулів не потрапляють.
12. Документація частково англійською ([EXTENSION_SDK.md](EXTENSION_SDK.md), [EXTENSION_LIFECYCLE.md](EXTENSION_LIFECYCLE.md)) і частково українською.

Що зроблено добре: модуль не може зламати ядро (усе оголошується в маніфесті й перевіряється до встановлення), будь-яку версію можна вимкнути або відкотити одним кліком, а trusted-модуль, що падає, вимикається сам і не кладе магазин.
