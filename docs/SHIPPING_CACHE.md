# Shipping directory cache policy

Carrier location data is treated as transient operational data, not as a business database.

- The platform never downloads a complete carrier branch/locker directory by default.
- City/settlement searches are cached as query results only, normally for 6 hours.
- Branch/locker searches happen only after a settlement is selected and are cached for about 10 minutes.
- No branch catalogue is stored in commerce tables.
- `cache.app` may use filesystem, Redis or another Symfony cache adapter. Expired data is disposable.
- Clearing application cache must never damage orders, customers or configured shipping methods.
- Historical orders keep their own immutable fulfillment snapshot and therefore do not depend on cached carrier directories.
- When a carrier API is unavailable, checkout falls back to manual delivery details instead of blocking the order.

This keeps the installation small even when several carriers expose tens of thousands of pickup locations.
