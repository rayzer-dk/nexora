# Implementation roadmap

Phase 1 — immutable foundations
Core package lifecycle, configuration, module manifests, permissions, event bus, migrations, health model, database conventions, REST/GraphQL boundaries, update contracts and storefront design system.

Phase 2 — sellable vertical slice
Catalog -> product page -> cart -> minimal checkout -> shipping provider -> payment provider -> order -> confirmation. SEO/JSON-LD and analytics events are implemented inside this slice, not postponed.

Phase 3 — operator-grade store
Admin catalog/order/customer screens, import/export, media engine, queue, scheduler, email, backup, updater, audit log and role-based permissions.

Phase 4 — growth stack
Search, feeds, Merchant Center, ads connectors, server-side events, reporting, attribution, abandoned carts, reviews, loyalty and content/page builder.

Phase 5 — scale and ecosystem
Marketplace extension sandbox, app permissions, signed packages, external search adapters, Redis, object storage, multi-store/markets, B2B and AI/agent capabilities.

Release rule: no phase may introduce file patching or direct cross-module database coupling to move faster.
