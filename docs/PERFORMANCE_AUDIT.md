# Performance audit

The project defines explicit storefront/admin latency budgets in `config/performance/budgets.json` for 10k, 100k and 500k product profiles.

Run static readiness without a deployed store:

`php bin/performance-audit.php 500k`

Run live HTTP probes against a deployed environment:

`PERF_BASE_URL=https://shop.example php bin/performance-audit.php 100k`

The live runner performs repeated requests and evaluates p95 latency. It is intentionally not presented as a substitute for full load testing. Production certification must run realistic concurrent traffic with a populated MySQL and MariaDB dataset and record category, search, product, cart, checkout, admin catalog, imports, sitemap/feed generation and background workers.

Required test datasets are 10,000, 100,000 and 500,000 products with representative variants, prices, stock, categories, attributes, media and orders. Query plans must be captured with EXPLAIN/EXPLAIN ANALYZE before adding indexes. New indexes are accepted only for measured hot paths and must be checked for write-amplification and redundancy.

The current SOURCE package has no database or web server in its release artifact, so a static-readiness result is not a performance claim.

## Concurrent traffic profile

The repository includes `qa/load/k6-storefront.js`. Run the same populated database at progressively higher concurrency, for example `VUS=50`, `VUS=250` and `VUS=1000`, while collecting DB, PHP-FPM, queue and host metrics. Do not treat virtual-user count as a capacity guarantee: the accepted capacity is the highest level that remains inside the configured p95/p99/error budgets on the target topology.

## Core Web Vitals

- Real visitors: a quarter of the visits report LCP, CLS and INP once, when the page is hidden (`/rum/vitals`, no identifiers, table `mc_web_vital`, kept 90 days). Admin → Analytics → Traffic → Site speed shows the 75th percentile per page kind and device, rated against the Google limits (LCP 2500 ms, INP 200 ms, CLS 0.1).
- Lab: `tests/e2e/web-vitals.spec.ts` keeps CLS under 0.1 and LCP under 4 s on the home page, catalogue and cart, and checks that the first catalogue cards are loaded at once (`loading=eager`, `fetchpriority=high` for the first two).
- The first request for a picture size that was not generated yet builds it on the spot, which can make the first visitor's LCP several seconds slower; warm the media cache after a large import.
