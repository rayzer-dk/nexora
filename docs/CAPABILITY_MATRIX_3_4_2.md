# Nexora Commerce 3.4.2 — capability matrix

This file is the release source of truth for feature maturity. A menu item or system-module declaration alone does not mean a feature is production-certified.

## Stable source implementation

Catalog CRUD, categories, brands baseline, variants baseline, attributes baseline, inventory/reservations, pricing, cart, checkout foundation, orders, customer accounts, wishlist, product reviews/Q&A, promotion/coupons, media library, SEO router/redirect history, sitemaps/feeds, content/blog, RBAC/store scopes, audit log, extension contracts, queue/outbox, backup/update foundations, shipping/payment provider contracts, store identity, theme tokens and core SSR storefront.

## Beta — operational but still requires real environment certification

Returns/RMA, saved carts, compare, recommendation engine (manual/hybrid/auto related + co-purchase cross-sell), navigation manager, Page/Header/Footer/Product/Checkout builders, Multi-Store host routing, multi-currency switching, multilingual UI layer, Google Commerce, analytics event layer, lifecycle marketing automation, first/last-touch attribution, Migration Center, Public REST API v1, forum foundation, provider refunds, carrier API shipment creation/tracking adapters and recovery/update executor. Operational shipment registry, manual/external tracking registration, return shipments, labels and bulk shipment workflow are implemented; provider-side TTN creation remains Beta until each carrier adapter passes sandbox E2E.

## Foundation — intentionally disabled by default

No customer-facing commerce module remains Foundation-only. Gift Cards, Loyalty, B2B and Recommendations now have operational server-side flows and remain Beta until live environment certification.

## Planned — intentionally disabled

No system module remains Planned-only in 3.4.2. Developer Tools is now a read-only Beta diagnostics center; arbitrary PHP, shell and SQL execution are intentionally excluded from the web admin.

Developer Tools product surface.

## Certification still required before Stable release

A reproducible Composer lock and npm lock, installed vendor dependencies, compiled Vite assets, immutable PRODUCTION package, clean install and upgrade on supported MySQL/MariaDB versions, browser/device E2E, payment/shipping provider sandbox E2E, concurrent checkout/inventory tests, queue crash/retry tests, backup/restore disaster-recovery tests, accessibility audit, security DAST/dependency audit and populated-database load tests.
