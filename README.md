# Nexora Commerce 3.20.1

Сучасна модульна e-commerce платформа для України та Європи. Один канонічний вихідний код у гілці `main`.

- **Стек:** PHP 8.4–8.5, Symfony 8.1, Twig SSR-витрина, адмінка на Vue 3.5 + TypeScript 6, Vite 8, MySQL 8.4 / MariaDB 10.11–11.4 (InnoDB, utf8mb4).
- **Ліцензія:** MIT. Базовий self-hosted магазин не потребує платних компонентів.
- **Extension API:** 2.0 (Settings Schema v2). Database schema: 52.

## Можливості

- **Каталог і склад:** товари, варіанти, атрибути, бренди, категорії з візуальним мерчандайзингом, резервування залишків, стани наявності (під замовлення, передзамовлення, очікується, повідомити), цифрові товари з приватним сховищем.
- **Продажі:** постійний кошик, fulfillment-driven checkout з конструктором, серверний Promotion/Coupon Engine, подарункові сертифікати та бонусні рахунки, B2B (компанії, кредитні ліміти, прайс-листи, погодження замовлень), незмінні order snapshots та idempotency, рахунки й credit note з PDF.
- **Оплата:** monobank acquiring (ECDSA-webhook), банківський переказ, накладений платіж, B2B-рахунок.
- **Доставка:** Nova Poshta — повний життєвий цикл (ТТН, скасування, tracking). Укрпошта, Meest, Delivery Auto — довідники локацій і ручне оформлення відправлень.
- **Пошук і SEO:** вбудований індекс з опціональним Meilisearch; єдиний SEO Router (canonical, hreflang, structured data, sitemap, редиректи), фіди Google Merchant, Meta, Pinterest, TikTok, Rozetka, Prom.ua, AI/Agentic JSONL.
- **Мультимагазин:** ізоляція даних за магазином, мови, валюти з курсами НБУ, ринки.
- **Адміністрування:** візуальний редактор витрини (Desktop/Tablet/Mobile), Media Library, Bulk Editor, збережені представлення, RBAC deny-by-default з доступом за магазинами, журнал дій, Cron Center, Backup/Restore, Migration Center (OpenCart/ocStore), Update Center з rollback.
- **Комплаєнс:** consent-банер, права споживача, експорт і видалення персональних даних, Google Consent Mode.
- **Розширення:** Extension API 2.0 — маршрути, події, блоки, UI-слоти, провайдери оплати/доставки/AI; ізоляція й карантин збійних розширень. Приклади й теми — у `bonus/`.

Google Commerce потребує перевірки з реальним Merchant Center (`integration_pending` до налаштування).

## Локалізація

`uk-UA` — канонічна мова інтерфейсу. Нові ключі додаються в українські каталоги; `en-US`, `de-DE`, `da-DK`, `pl-PL`, `ru-RU` використовують український fallback. Текст не вбудовується в PHP, Twig, JS/TS — це контролює `php bin/localization-contract-check.php`.

Нова установка створює магазин для України: країна `UA`, мова `uk-UA`, валюта `UAH`, часовий пояс `Europe/Kyiv`, системні URL англійською (`/cart`, `/checkout`, `/shipping`).

## Встановлення

Повна інструкція: `docs/INSTALLATION.md`.

1. Розпакуйте повний release ZIP (містить `vendor/` і зібрані assets).
2. Направте Document Root домену на `public/`.
3. Відкрийте `/setup.php` і пройдіть установку (за бажанням — з демо-даними; пізніше: `commerce:demo:install` / `commerce:demo:remove`).
4. Додайте в cron один запис: `php bin/console commerce:cron:run` кожні 5 хвилин.

## Розробка і перевірки

```
composer install
npm ci
npm run qa                      # typecheck, lint, збірка, Playwright
php bin/release-check.php       # release-контракт і статичні перевірки
php bin/console commerce:system:check
```

Усі скрипти `bin/*-check.php` — автономні release-гейти; ті, що потребують БД (`*-runtime-check.php`), запускаються з `DATABASE_URL` у CI на MySQL/MariaDB. CI: `.github/workflows/`. Реліз-пакет збирає `tools/build-production.sh`.

## Документація

Каталог `docs/`: архітектура (`ARCHITECTURE.md`), матриця зрілості функцій (`CAPABILITY_MATRIX.md`), безпека (`SECURITY.md`), розробка розширень (`DEVELOPER_GUIDE_UK.md`), оновлення (`UPDATE_CENTER.md`), розгортання (`DEPLOYMENT.md`).

Політика гілок: `docs/CANONICAL_SOURCE.md` — єдина гілка `main`, без паралельних версій.
