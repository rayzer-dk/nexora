# Production worker topology

Small stores can run the default single-node filesystem cache and bounded post-response work without Redis/Valkey or Meilisearch.

High-traffic installations should separate web PHP-FPM from durable workers. The repository includes `deploy/supervisor/nexora-commerce-workers.conf.example` for event, notification and external-integration workers. Image/media transformation should run in its own process pool when bulk optimization is enabled.

For multi-node traffic, shared ephemeral state should use Redis or Valkey. `cache.app` is configurable via `CACHE_APP_ADAPTER`; the optional `scale` environment switches sessions and cache to Redis. Locks use `LOCK_DSN`. Authoritative catalog/order/inventory state remains in MySQL/MariaDB.

Never route Merchant API, marketing APIs, mail sending or bulk image processing through the shopper request lifecycle.

## Required production checks

Run `php bin/console commerce:queues:status` from monitoring/cron. A non-zero exit means dead/failed/stalled work or a backlog older than 15 minutes and requires attention.

Run `php bin/console commerce:queues:purge --days=30` periodically (for example daily). It deletes only old successful records. Pending, retry, dead, failed and delivered-with-failures records are preserved for diagnosis. The purge is bounded per run.

The integration worker uses an explicit database lease. If a process dies after claiming a Google/Marketing job, another worker returns the stale job to `pending` after five minutes instead of leaving it stuck forever.
