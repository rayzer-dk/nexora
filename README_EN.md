# Nexora Commerce 3.62.0

Modern modular e-commerce platform for Ukraine and Europe. One canonical source: the `main` branch.

- **Stack:** PHP 8.4–8.5, Symfony 8.1, Twig SSR storefront, Vue 3.5 + TypeScript 6 admin, Vite 8, MySQL 8.4 / MariaDB 10.11–11.4 (InnoDB, utf8mb4).
- **License:** MIT. A basic self-hosted store needs no paid components.
- **Extension API:** 2.0 (Settings Schema v2). Database schema: 52.

## Features

- **Catalog and stock:** products, variants, attributes, brands, categories with visual merchandising, stock reservation, availability states (backorder, pre-order, coming soon, notify me), digital products with private storage.
- **Sales:** persistent cart, fulfillment-driven checkout with a builder, server-side Promotion/Coupon Engine, gift cards and loyalty accounts, B2B (companies, credit limits, price lists, order approval), immutable order snapshots and idempotency, invoices and credit notes with PDF.
- **Payments:** monobank acquiring (ECDSA-verified webhook), bank transfer, cash on delivery, B2B invoice.
- **Shipping:** Nova Poshta — full lifecycle (waybill, cancellation, tracking). Ukrposhta, Meest, Delivery Auto — location lookup and manual shipment handling.
- **Search and SEO:** built-in index with optional Meilisearch; one SEO Router (canonical, hreflang, structured data, sitemap, redirects); Google Merchant, Meta, Pinterest, TikTok, Rozetka, Prom.ua and AI/Agentic JSONL feeds.
- **Multi-store:** per-store data isolation, languages, currencies with NBU rates, markets.
- **Administration:** visual storefront editor (Desktop/Tablet/Mobile), Media Library, Bulk Editor, saved views, deny-by-default RBAC with per-store access, audit log, Cron Center, Backup/Restore, Migration Center (OpenCart/ocStore), Update Center with rollback.
- **Compliance:** consent banner, consumer rights, personal data export and erasure, Google Consent Mode.
- **Extensions:** Extension API 2.0 — routes, events, blocks, UI slots, payment/shipping/AI providers; failing extensions are isolated and quarantined. Examples and themes are in `bonus/`.

Google Commerce needs verification against a real Merchant Center (`integration_pending` until configured).

## Localization

`uk-UA` is the canonical UI language. New keys go into the Ukrainian catalogs; `en-US`, `de-DE`, `da-DK`, `pl-PL`, `ru-RU` fall back to Ukrainian. Text must not be embedded in PHP, Twig or JS/TS — enforced by `php bin/localization-contract-check.php`.

A fresh install creates a Ukrainian store: country `UA`, language `uk-UA`, currency `UAH`, time zone `Europe/Kyiv`, English system URLs (`/cart`, `/checkout`, `/shipping`).

## Installation

Full guide: `docs/INSTALLATION_EN.md`.

1. Unpack the full release ZIP (contains `vendor/` and built assets).
2. Point the domain Document Root to `public/`.
3. Open `/setup.php` and complete the installer (optionally with demo data; later: `commerce:demo:install` / `commerce:demo:remove`).
4. Add one cron entry every 5 minutes (the ready-to-paste line with absolute paths is shown in admin System → Cron): `*/5 * * * * /usr/bin/php /path/to/bin/console commerce:cron:run >/dev/null 2>&1`. Without cron access use the secret web URL or the built-in fallback.

Run `php bin/console commerce:system:check` at any time to re-check PHP, extensions, writable paths and database.

## Development and checks

```
composer install
npm ci
npm run qa                      # typecheck, lint, build, Playwright
php bin/release-check.php       # release contract and static checks
php bin/console commerce:system:check
```

All `bin/*-check.php` scripts are standalone release gates; those needing a database (`*-runtime-check.php`) run in CI on MySQL/MariaDB with `DATABASE_URL`. CI: `.github/workflows/`. The release package is built by `tools/build-production.sh`.

## Documentation

See `docs/`: architecture (`ARCHITECTURE.md`), feature maturity matrix (`CAPABILITY_MATRIX.md`), security (`SECURITY.md`), extension development (`DEVELOPER_GUIDE.md`), updates (`UPDATE_CENTER.md`), deployment (`DEPLOYMENT.md`).

