# Cache maintenance

Shipping directories use a dedicated transient cache pool named `cache.shipping`.

Default behavior:

- filesystem-backed transient cache for a simple single-server installation;
- can be changed to Redis without changing shipping providers;
- city-query TTL: normally 6 hours;
- pickup-point-query TTL: normally 10 minutes;
- cached values are never the source of truth for orders;
- clearing `cache.shipping` is always safe.

The whole disposable carrier cache can be cleared safely with:

`php bin/console commerce:shipping-cache:clear`

A production scheduler may run this daily or during low traffic. Redis-backed cache entries also expire automatically by TTL. The command never touches orders or shipping settings.

## Generated image cache

The daily media GC removes obsolete generations and disabled formats after its grace period.
Optional server settings (default zero, disabled): `MEDIA_CACHE_MAX_MB` sets a soft
MiB budget; `MEDIA_CACHE_IDLE_DAYS` removes variants whose modification and access times
are older than the specified days. Enable the idle policy only on a filesystem that
updates access times reliably (not noatime). A cache limit is best-effort, not a filesystem
quota: scans have a time budget, eviction candidates are bounded, and recently accessed
or newly created variants are protected. With either policy enabled, scheduled mass
warm-up is skipped, but requested AVIF jobs and on-demand image generation continue.

For an explicit dry run: `php bin/console commerce:media:gc --max-cache-mb=2048`.
Add `--apply` to remove eligible variants. Original uploads are never removed by this policy.
