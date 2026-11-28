# Performance audit

The project defines explicit storefront/admin latency budgets in `config/performance/budgets.json` for 10k, 100k and 500k product profiles.

Run static readiness without a deployed store:

`php bin/performance-audit.php 500k`

Run live HTTP probes against a deployed environment:

`PERF_BASE_URL=https://shop.example PERF_PRODUCT_PATH=/actual-product PERF_CATEGORY_PATH=/actual-category php bin/performance-audit.php 100k`

The live runner performs repeated requests and evaluates p95 latency. It is intentionally not presented as a substitute for full load testing. Production certification must run realistic concurrent traffic with a populated MySQL and MariaDB dataset and record category, search, product, cart, checkout, admin catalog, imports, sitemap/feed generation and background workers.

Required test datasets are 10,000, 100,000 and 500,000 products with representative variants, prices, stock, categories, attributes, media and orders. Query plans must be captured with EXPLAIN/EXPLAIN ANALYZE before adding indexes. New indexes are accepted only for measured hot paths and must be checked for write-amplification and redundancy.

The current SOURCE package has no database or web server in its release artifact, so a static-readiness result is not a performance claim.

## Concurrent traffic profile

The repository includes `qa/load/k6-storefront.js`. Run the same populated database at progressively higher concurrency, for example `VUS=50`, `VUS=250` and `VUS=1000`, while collecting DB, PHP-FPM, queue and host metrics. Do not treat virtual-user count as a capacity guarantee: the accepted capacity is the highest level that remains inside the configured p95/p99/error budgets on the target topology.

## Core Web Vitals

- Real visitors: a quarter of the visits report LCP, CLS and INP once, when the page is hidden (`/rum/vitals`, no identifiers, table `mc_web_vital`, kept 90 days). Admin → Analytics → Traffic → Site speed shows the 75th percentile per page kind and device, rated against the Google limits (LCP 2500 ms, INP 200 ms, CLS 0.1).
- Lab: `tests/e2e/web-vitals.spec.ts` keeps CLS under 0.1 and LCP under 4 s on the home page, catalogue and cart, and checks that the first catalogue cards are loaded at once (`loading=eager`, `fetchpriority=high` for the first two).
- The first request for a picture size that was not generated yet builds it on the spot, which can make the first visitor's LCP several seconds slower; warm the media cache after a large import.

The dedicated Performance and network audit workflow measures cold/warm browser loads on real demo routes at desktop, simulated 4G and slow 3G, records LCP, CLS, TTFB and transfer bytes, and exercises Workbox offline behavior. Demo results are not a production capacity claim.

For the dedicated audit only, E2E_STATIC_CACHE=1 enables static cache headers, ETags and precompressed assets in the local PHP router. This distinguishes production-like warm loads from the default PHP development server. The report identifies the six largest transferred resources per route. Network-adaptive search cancels obsolete requests and increases debounce on constrained connections; optional quick-view description fetching is omitted on slow links or Save-Data, while the product link remains available.

Default light/dark branding is lazy loaded so the hidden variant is not fetched until selected. Hero and promotional images use named media srcsets; offscreen catalog cards have low fetch priority. Speculative navigation is disabled when the request carries Save-Data: on. The dedicated audit checks these properties and simulates public media cache headers as well as build assets.

The separate Server cache and SQL audit runs on isolated demo databases for filesystem, Redis and Valkey profiles. It records representative read-only EXPLAIN JSON plans, first/repeated HTTP timings, concurrent 4/8-client traffic, Redis/Valkey hit counters and memory snapshots. It requires shared cache hits for the two distributed profiles. These are demo smoke measurements, not certification of 10k/100k/500k catalogs. Equivalent filter permutations now share a cache identity without changing query semantics.

Large catalog load audit: isolated MySQL and Valkey jobs generate exactly 10,000, 100,000 and 500,000 products, with real SEO routes, prices, category membership, variants and SQL search documents. The synthetic additions are single-variant digital products in one locale/category, without extra inventory or images; this is a cardinality baseline, not a realistic full-store certification. The seed script refuses all databases except the explicitly authorized disposable loopback CI database. Reports include fixture size, SQL plans, repeated HTTP latency and 4/8/16-client throughput. Do not extrapolate results to production capacity.

Motion: catalog skeletons mirror the existing product count, card dimensions and responsive grid; initial SSR never waits for skeletons. Resource-constrained clients use a light animation budget based on Save-Data, connection type, device memory and CPU count. Same-origin cross-document View Transitions opt in only with full motion, sufficient resources and browser support. Touch/constrained clients avoid backdrop blur and animated shadows. Dedicated rendering diagnostics sample frame cadence and long tasks; shared CI FPS is informational, not a hardware guarantee.
