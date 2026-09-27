# Ukraine shipping architecture

Built-in provider adapters:

- Nova Post (`nova_post`)
- Ukrposhta (`ukrposhta`)
- Meest ПОШТА (`meest`)
- Delivery (`delivery_auto`)

## Location policy

The checkout does not maintain a complete branch/locker database by default.

1. Search/select a settlement.
2. Cache settlement results locally.
3. Only after the settlement is selected, query the carrier for current branches/lockers in that settlement.
4. Save the selected provider point ID plus an immutable human-readable snapshot into the order.

This keeps local data small and avoids stale branch directories. Short-lived response caching is allowed, but branch lists are not treated as authoritative persistent store data.

## Failure policy

A carrier API failure must never block order creation.

- Each provider is isolated from other providers.
- Checkout calls use short timeouts.
- Repeated failures open a temporary circuit breaker.
- When the provider is unavailable, the UI exposes manual destination entry.
- Manual selections are stored with `verification=unverified_manual_fallback` so staff can verify them later.
- Other providers remain usable.

## Provider specifics

Nova Post: settlements are derived from the live divisions API and can be cached locally; points are requested after a settlement is selected.

Ukrposhta: city search uses the address classifier `get_city_by_name`; offices are requested by `CITY_ID` through `get_postoffices_by_postcode_cityid_cityvpzid`. Closed/security-only records are filtered.

Delivery: city search uses public `GetAreasList`; warehouses are loaded by selected city using `GetWarehousesListByCity`.

Meest: the integration adapter is built into the platform, but production endpoint details and credentials are contract-specific and therefore configured from the merchant's Meest agreement rather than hard-coded.
