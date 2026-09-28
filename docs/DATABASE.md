# Database architecture

Nexora Commerce uses current LTS database tracks. Production deployments target the certified MySQL 8.4 LTS or MariaDB 10.11 LTS+ baseline (11.4 LTS recommended). The SQL deliberately avoids vendor-specific shortcuts where they would damage portability between these two engines.

Internal relations use compact `BIGINT UNSIGNED` keys for fast InnoDB joins. Public URLs/API identifiers use 128-bit UUIDv7 values stored as `BINARY(16)`, so sequential database IDs are never exposed.

All text tables use `utf8mb4`. The installer selects the newest suitable collation supported by the actual MySQL/MariaDB server instead of forcing a server-specific collation into module SQL. Unicode input is normalized to NFC at application boundaries.

Money is stored as integer minor units plus ISO-4217 currency; floating point is not used for prices. Timestamps are UTC `DATETIME(6)`.

The schema is multistore-aware: product publication and translations/slugs are store scoped, while a core product identity can be shared. Categories are store scoped. Historic orders store snapshots so catalog/module changes cannot corrupt old orders.

## Index policy

Indexes are deliberately limited to known hot paths: product publication, store/locale slug resolution, SKU/GTIN/MPN, category browsing, filterable attributes, price lookup, stock lookup, cart cleanup, order lists/search, tracking, payment idempotency, queue workers, audit/security investigations and component/update state.

Long URLs and media storage keys use SHA-256 hash indexes while retaining the complete original value. This avoids oversized or prefix-based unique indexes.

Every future index must correspond to a real query and be checked with `EXPLAIN`; duplicate/redundant indexes are not accepted.

SQL full-text is not the long-term catalog search strategy. Small catalogs may use the database adapter; larger catalogs can switch to the Search Provider without changing Catalog entities.

## Schema v2 additions

0.7 adds explicit locale/currency registries, per-store locale and currency activation, exchange-rate observations, first-class brands/manufacturers, Google taxonomy mappings, and resumable migration jobs.

Schema v3 removes the development-era free-text product brand field; `brand_id` is canonical. It also normalizes variant options, introduces markets, price lists/rules, inventory items/stock levels/reservations, entity revisions, order events, idempotency keys and namespaced extension metadata. See `DATABASE_DESIGN_REVIEW.md`.

## Schema v3 commerce improvements

`mc_market` separates commercial context from storefront identity. Market-country and market-location links let a single EU/UA storefront expose different currency/tax/warehouse contexts without duplicating products.

`mc_inventory_item`, `mc_variant_inventory_item`, `mc_stock_level` and `mc_inventory_reservation` decouple sellable variants from physical stock. This supports normal variants, kits, bundles and shared inventory and provides a safe reservation path for concurrent checkout.

`mc_price_list` and `mc_price_rule` extend base variant pricing without turning catalog records into rule containers. Base-price lookup remains direct, while campaign/B2B/market rules are evaluated only when relevant.

`mc_entity_revision` is not queried by normal storefront product reads. Draft/version history therefore cannot slow down the published catalog path.


## Schema v4 SEO routing

Schema v4 adds `mc_seo_route` and `mc_seo_redirect`. Paths are unique by store + locale using SHA-256 path hashes while retaining the full path for collision verification and diagnostics. Redirects target a canonical route ID rather than another URL, preventing redirect chains after multiple slug changes. New store column defaults are UA / uk-UA / UAH / Europe/Kyiv.

## Schema v5 units, content and update safety

Schema v5 adds normalized measurement units, fractional sale quantity rules, CMS/blog content storage, product reviews, deliberate SEO facet landing pages and update checkpoints. Inventory remains DECIMAL-based so unit changes do not require a second stock model.

Schema v6 adds protected VAT/tax tables and effective dates, immutable order tax snapshot fields, economic operators/EU responsible persons, product compliance translations, product documents/relations and variant shipping dimensions.
