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
