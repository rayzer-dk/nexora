# Architecture decision baseline

The platform is a modular monolith. Deployment stays simple, while code boundaries are strict enough to split selected domains into services later if scale demands it.

Core owns bootstrapping, dependency injection, module lifecycle, permissions, event dispatch, configuration, migrations, update orchestration, health checks and public extension contracts.

System modules own commerce behavior: Catalog, Pricing, Inventory, Customer, Cart, Checkout, Order, Payment, Shipping, Tax, Promotion, CMS, Blog, SEO, Marketing, Analytics, Media, Search, Notification, Import/Export, Queue, Scheduler, Backup, API, AI and Developer Tools.

Rules:
1. A module must not edit Core files.
2. A module must not read another module's tables directly. It uses an application contract/API.
3. Public contracts are versioned.
4. Breaking changes require a new contract/API version and a migration window.
5. Disabled optional modules must not add storefront CSS/JS, queries, jobs or hooks.
6. Storefront rendering is server-first. Interactive code hydrates only the component that needs it.
7. Admin is a separate application client of the same application layer.
8. External integrations are adapters behind provider contracts.
9. Background tasks are idempotent and retry-safe.
10. Updates have preflight, backup, migration, health-check and rollback metadata.
