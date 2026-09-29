# Посібник розробника розширень Nexora Commerce

Це основна інструкція для сторонніх модулів та інтеграцій. Модуль не повинен змінювати файли ядра та залежати від недокументованих внутрішніх класів. Орієнтуйтеся на опублікований Extension API поточної версії.

## Який тип розширення обрати

`declarative` — налаштування, переклади, Builder-блоки, безпечний CSS та декларативні сторінки без стороннього PHP.

`remote_app` — інтеграція з CRM/ERP/SaaS через HTTPS та публічні API/webhook-контракти Nexora.

`trusted_release` — лише коли потрібні PHP/JavaScript, міграції, внутрішні route handlers або обробники подій. Такий пакет повинен бути підписаний Ed25519 ключем довіреного видавця.

## Швидкий старт

    php bin/console commerce:extension:scaffold acme.loyalty --name="Acme Loyalty"
    php bin/console commerce:extension:scaffold acme.product_badge --name="Product Badge" --preset=product-block
    php bin/console commerce:extension:scaffold acme.crm --name="Acme CRM" --preset=remote-integration
    php bin/console commerce:extension:scaffold acme.erp --name="Acme ERP" --preset=trusted-route

Генератор створює manifest, settings schema v2, локалізацію та правильний PHP namespace з коду модуля.

## Основні правила

ZIP має містити `manifest.json` у корені. Ідентифікатори routes, permissions, blocks і перекладів повинні бути у namespace розширення. `hot_disable` має залишатися увімкненим. Дані модуля не видаляються при простому вимкненні.

Налаштування schema v2 підтримують text, textarea, number, integer, boolean, select, url, email, secret, color, range, multilingual_text та multilingual_textarea, а також group, placeholder і advanced. Секретні значення не можна пакувати як реальні default-значення.

Переклади зберігаються всередині модуля. `uk-UA` — обов'язковий fallback. Не копіюйте мовні файли у Core.

Стабільні UI extension points можна побачити командою:

    php bin/console commerce:extension:list-points

Публічні події:

    php bin/console commerce:extension:list-events

Admin routes дозволені лише під `/admin/extensions/<module>/...` і повинні мати permission самого модуля.

Для signed trusted_release доступні офіційні provider-контракти без редагування Core: `provider.payment`, `provider.shipping`, `provider.product_block`, `provider.ai`. Реалізація реєструється через `TrustedExtensionContext`; дубль коду провайдера або незаявлена capability відхиляються. Деталі: `docs/EXTENSION_PROVIDER_CONTRACTS.md`.

Міграції БД дозволені лише signed trusted_release. Nexora фіксує SHA-256 кожної виконаної міграції та не запускає її повторно. Вимкнення модуля не повинно знищувати дані.

Remote app не повинна підключатися безпосередньо до БД магазину. Використовуйте API/webhooks, автентифікацію, підпис запитів, replay protection та idempotency.

## Перевірка і збірка

    php bin/console commerce:extension:validate module.zip
    php bin/console commerce:extension:test module.zip
    php bin/console commerce:extension:pack path/to/module --output=dist/module.zip

Trusted package підписується після фінальної збірки. Ключ видавця створюється один раз:

    php bin/console commerce:extension:keygen acme.2026 --out=acme.2026.key

Команда друкує фрагменти для `manifest.json` (`publisher`/`signature`) та запис публічного ключа для `config/extensions/trusted-publishers.json` кожного магазину, де модуль має працювати. Секретний ключ не зберігайте в репозиторії. Далі:

    php bin/console commerce:extension:pack path/to/module --output=module.zip   # для trusted покаже, що підпису ще немає
    php bin/console commerce:extension:sign module.zip acme.2026.key

Після підпису знову запустіть validate і test.

## Регіони блоку товару

Блок з `surface: product` повинен оголошувати регіони сторінки товару: `hero_media`, `hero_summary`, `below_primary`, `below_secondary`, `mobile_sticky`. Інші назви валідатор відхиляє. Розмістити блок на сторінці товару можна в Оформлення → Конструктор → Товар.

## Життєвий цикл

Завантаження ZIP не активує модуль. Nexora спочатку перевіряє і stage-ить пакет. Непідписаний executable package переходить у quarantine. Під час активації спочатку готуються міграції/assets, і лише після успіху перемикається active version. Якщо активація падає, попередня робоча версія залишається активною.

Модуль повинен коректно переживати disable/enable, upgrade з попередньої версії та rollback. Код вимкненого модуля не повинен виконуватися.

Повний технічний опис англійською: `docs/DEVELOPER_GUIDE.md`. Готові приклади: `docs/examples/extensions/`.


Developer inspection commands:

```bash
php bin/console commerce:extension:list-points
php bin/console commerce:extension:list-events
php bin/console commerce:extension:list-contracts
```

## Theme SDK та оновлення модулів

Nexora підтримує безпечні декларативні теми та підписані trusted-теми. Декларативна тема містить CSS, шрифти й зображення. Підписана trusted-тема може додатково перевизначати Twig-шаблони вітрини через каталог `templates/`, не змінюючи Core. Перевизначення `admin`, `email` та `order_document` заборонені.

Нову версію модуля не потрібно копіювати поверх старих файлів. Зберіть ZIP з новою семантичною версією і встановіть його через менеджер розширень. Nexora збереже попередню версію, виконає міграції, перевірить пакет і лише після цього атомарно перемкне активну версію. Попередня версія залишається доступною для rollback.

Перед оновленням Core система перевіряє всі активні модулі проти цільової версії Core та Extension API. Несумісне оновлення блокується до перемикання релізу. Якщо trusted-модуль падає під час запуску, Nexora вимикає тільки цей модуль, записує помилку в журнал і продовжує роботу Core.

У «Розширеннях» є журнал життєвого циклу модулів. Загальні критичні помилки дивіться у «Система > Стабільність».

## Універсальний імпорт CSV/XLSX

Для стороннього експорту відкрийте «Імпорт та експорт > Універсальний CSV/XLSX». Завантажте файл, перевірте перші рядки, зіставте колонки з полями Nexora та спочатку запустіть режим перевірки. Обов’язкові поля: SKU, Назва, Ціна. Для повної міграції OpenCart/ocStore з категоріями, мовами, SEO, клієнтами та замовленнями використовуйте Migration Center.


## Commercial and hosted extensions

Use `commercial.model` = `free`, `one_time`, `subscription` or `external`. A `remote_app` installs a connector manifest/settings package; it does not execute code fetched from the developer server. Authentication uses API/OAuth credentials configured after installation. Signed `trusted_release` packages carry their executable code inside the ZIP. See `docs/COMMERCIAL_EXTENSIONS.md`.

## Видалення

Активну версію видалити не можна: спочатку вимкніть модуль. У розділі Система → Розширення кнопка «Видалити» прибирає файли, ресурси та налаштування вибраної версії. Позначка «З даними модуля» додатково виконує `down`-міграції; без неї таблиці модуля залишаються, тому міграції мають бути ідемпотентними при повторному встановленні. Збережений блок видаленого модуля у макетах безпечно пропускається на вітрині, а Конструктор показує попередження.
