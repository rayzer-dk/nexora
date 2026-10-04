# Scalability and catalog capacity

## Practical target

The architecture is designed so that 5,000-50,000 products are a normal operating range, not an exceptional case. The schema uses narrow internal BIGINT keys, explicit store/market availability tables, transactional inventory reservations, asynchronous outboxes and indexed catalog relations.

Catalog size alone does not determine capacity. Concurrent dynamic requests, search complexity, faceting, cache hit rate, image delivery, database memory and checkout write contention are usually more important.

## 3.0.5 hardening

Storefront catalog/category/facet/product reads now use a bounded application cache. Product lists and product pages use an 8 second TTL; checkout, cart mutations and inventory reservations never trust cached stock. Facets use 60 seconds and category navigation 300 seconds. This reduces repeated SQL work while preserving live validation for purchase operations.

Additional composite indexes cover the storefront translation join, primary variant lookup, review aggregation, price context lookup and product attribute lookup.

## High concurrency

For one application node, filesystem-backed cache remains a safe default. For sustained parallel traffic or multiple application nodes, Redis/Valkey should back application cache, sessions, locks and other distributed ephemeral state. Media should be served by Nginx/CDN/object storage rather than PHP.

Checkout stock safety is based on atomic conditional UPDATE statements and idempotency keys. Two buyers attempting to purchase the final unit cannot both successfully reserve it if they reach the same authoritative database.

## Remaining validation before claiming capacity

No fixed number of simultaneous buyers is claimed without a live load test. Acceptance requires measurements on the intended server topology with realistic product data, category distributions, filters, sessions and checkout writes. Test at least p50/p95/p99 latency, error rate, DB CPU, buffer-pool hit rate, lock waits, deadlocks, active connections, queue lag and PHP worker saturation.

For 50,000 products, the next performance priorities are optional Meilisearch for complex text/facet search, shared Redis/Valkey, reverse-proxy/CDN caching, database tuning and real load tests. For 100,000-500,000 products, a dedicated search provider and denormalized search/read projections become strongly recommended.

## 3.0.6 scale path

The next scale layer is now implemented without making it mandatory for small shops. Meilisearch is optional and returns ranked candidate IDs only; any disablement, timeout or provider failure falls back to canonical SQL. Durable `mc_storefront_product_projection` records feed external indexing and are refreshed from catalog and inventory-affecting order events.

Popular facets can be precomputed into `mc_storefront_facet_projection`. Missing/expired projections transparently use live SQL. Deep machine/API traversal can use `/api/storefront/catalog/cursor` so very deep lists do not require `OFFSET page*limit`.

Shared Redis and Valkey profiles are opt-in. Default small-store installs keep filesystem application cache and do not require an external cache/search service.

Use `php bin/console commerce:db:observe` for DB counter snapshots and `tests/load/k6-storefront.js` for concurrent HTTP validation. Capacity claims still require a deployed dataset and measured p95/p99.

## Measured at 10 000 products (3.36.0)

A scratch copy of the demo store with 9 933 products, 11 137 variants and 10 558 SEO routes (the demo catalog cloned 300 times), PHP built-in server without OPcache (a deliberately slow baseline: an empty cart page costs ~190 ms there), MariaDB 10.11, every request answered from a cold cache:

| Page | Before | After |
| --- | --- | --- |
| Home | 910 ms | 420 ms |
| Catalog | 800 ms | 350 ms |
| Category | 900 ms | 400 ms |
| Search | 640 ms | 430 ms |
| Product page | 300 ms | 300 ms |

What was slow: the product-card query computed the rating, review count, stock and photo of **every** candidate product before sorting and cutting the page. It now selects the ids of the page first and then loads the card data for those few products. The "popular" order uses one grouped join over the last 180 days of sales instead of a subquery per product. Category lists that sort by rating or stock (merchandising modes) still use the single query.

Reproduce: clone the demo catalog in a scratch database, run `commerce:search:reindex`, request the pages above with the application cache bypassed (wait 9 s between requests) and compare with `config/performance/budgets.json`.
