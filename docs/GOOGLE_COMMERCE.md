# Google Commerce integration

Google is a first-class commerce surface, not a feed add-on.

The system owns one canonical product projection and derives:
- Storefront product view;
- Schema.org Product/Offer structured data;
- Google Merchant API product data;
- AI/agent product context;
- future UCP checkout/cart payloads.

This prevents price, availability, identifiers and policies from drifting between channels.

Merchant API system package targets:
- Accounts
- Products
- Data Sources
- Inventories
- Promotions
- Reports
- Notifications
- Issue Resolution
- Reviews when eligible
- Order Tracking when eligible
- Quota/health diagnostics

The old Content API for Shopping is not used for new development.

UCP:
- /.well-known/ucp support is implemented behind GOOGLE_UCP_ENABLED;
- default protocol version in this prototype: 2026-04-08;
- UCP is not enabled automatically because rollout and merchant eligibility are still evolving;
- checkout, fulfillment and order lifecycle adapters must be versioned independently from Core.

Merchant API MCP:
- treated as experimental;
- never a reliability dependency;
- can later power admin diagnostics and AI-assisted Merchant Center issue resolution.

Product page rules:
- Product/Offer JSON-LD is emitted server-side in the initial HTML;
- current price, currency and availability use the same canonical offer projection as checkout;
- shipping and return policies can be emitted at organization level and overridden per offer when required;
- GTIN/MPN/brand are validated rather than invented;
- product variants will use ProductGroup/variant-aware URLs.

## Google Product Category policy (0.7)

Google taxonomy is not duplicated into the core catalog tree. Category-level mappings are optional defaults; product-level override wins; no mapping means Google automatic classification. Store category hierarchy continues to generate merchant-owned `product_type` values.

The taxonomy mapping layer is isolated in GoogleCommerce so Google taxonomy updates cannot force catalog migrations.

## Runtime worker (3.0.3)

Catalog domain events enqueue Google work into `mc_integration_sync_queue`. The storefront request never calls Merchant API directly.

`php bin/console commerce:integration:work --limit=100`

The worker projects the canonical store product, default variant, price, stock, canonical URL and media into Merchant API Products v1. Published products use `productInputs:insert` against an API data source. Non-published products are removed from the API data source. Google processing issues are persisted in `mc_google_merchant_product_state` and surfaced in `/admin/system/integrations`.

Credentials are supplied only through environment configuration. They are not stored in catalog tables. Service-account JSON/path is supported through `google/auth`; a short-lived static access token is available for diagnostics only.

Failure policy: external HTTP/auth/quota failures are retried asynchronously with exponential backoff. After the retry budget is exhausted the queue item becomes `dead` and can be reviewed/retried from admin. A Merchant failure never rolls back a product edit and never blocks storefront/checkout.
