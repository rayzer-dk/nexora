# Public API v1

Nexora Commerce 2.9.0 introduces a stable `/api/v1` foundation without making a headless frontend mandatory.

Initial read-only endpoints:

- `GET /api/v1`
- `GET /api/v1/catalog/products`
- `GET /api/v1/catalog/products/{publicId}`
- `GET /api/v1/openapi.json`

Catalog list queries reuse the same market/store/locale context and bounded Search/Filter rules as the SSR storefront. Internal database IDs are not exposed. Invalid sort/range input returns `application/problem+json` instead of propagating an exception.

Orders, customers and write operations are intentionally not exposed anonymously. Authenticated API credentials, scoped permissions, idempotency and webhook/event contracts must be completed before those resources become public.

GraphQL remains optional. The installed GraphQL library may later provide a read-oriented facade over the same Application contracts, but Core correctness and the default storefront do not depend on GraphQL or API Platform.
