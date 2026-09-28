# Commerce database design review — schema v3

Nexora Commerce deliberately borrows proven ideas from modern commerce platforms without inheriting their historical storage complexity.

## Adopted patterns

### Product, pricing and inventory are separate domains
A sellable product variant does not own stock rows or pricing logic directly. Pricing and inventory can evolve independently from catalog content. This keeps integrations, bundles and multiple warehouses manageable.

### Variant-first commercial data
SKU, GTIN, option combinations, stock and price resolve at variant level. A simple product is still represented by one default variant, so application code has one consistent purchasing model.

### Markets instead of cloned stores
A Store represents a storefront/brand/domain. A Market represents a commercial context such as Ukraine, Denmark or an EU group, with its own countries, locale/currency defaults, tax presentation, inventory locations and pricing context. A merchant does not need to duplicate the product catalog for every country.

### Inventory item separate from product variant
A variant can consume one or several inventory items. This supports normal products, shared stock, kits and bundles. Stock levels are maintained per inventory item and physical location. Reservations are first-class rows so simultaneous checkouts do not oversell stock.

### Price lists and rules
Base prices remain fast to resolve. Optional price lists can add scheduled sale, B2B or market-specific prices. Rule data is separate from the hot variant/catalog tables.

### Draft/version snapshots outside storefront hot paths
Draft revisions and rollback snapshots are stored separately. Published catalog reads remain compact. Revision payloads are checksummed and immutable.

### Typed catalog attributes instead of universal EAV
Filterable/comparable commerce attributes use typed value columns so numeric ranges, booleans and exact text values can be indexed predictably. A small namespace/key JSON metadata table is available for non-query-critical extension data. Extensions must not place filter/search-critical data in metadata.

### Immutable order history
Order lines already contain product/price snapshots. Schema v3 also adds an ordered event stream for status/business events. Removing a catalog item or extension cannot erase what was purchased.

### Idempotent write APIs
Checkout, payment callbacks and integration writes can be safely retried using hashed idempotency keys. This is essential for unreliable mobile networks and external webhook retries.

## Patterns intentionally not copied

We do not use a universal Magento-style EAV model for all product data, because it spreads ordinary reads across many tables and makes index/query behavior harder to predict. We also do not duplicate every entity per channel/market; market linkage is added only where commercial behavior actually changes.

## Index policy

Schema v3 continues the rule that every index must support a known access pattern. Stock worker paths use inventory-item/location indexes; price resolution uses variant/store/market/currency/quantity context; revision and idempotency cleanup have dedicated indexes. All future production releases require EXPLAIN checks against representative catalog sizes before an index is accepted.

## Pre-1.0 migration policy

Development releases retain incremental Doctrine migrations so upgrading test installations is possible. Before 1.0, the development migration chain will be squashed into a clean production baseline plus only genuinely released upgrade migrations. This avoids shipping development-era create/drop churn to new production stores.

## Additional decisions after cross-platform review

- Categories are catalog entities, not store-owned rows. Store and market publication lives in bridge tables, so the same taxonomy can be reused without cloning records.
- Market availability is explicit for products and categories. This follows the useful channel idea without creating a second parallel catalog.
- Price rows have deterministic priority and quantity-range validation.
- Stock reservation is an atomic database operation, not a read-then-write check. The conditional UPDATE prevents two concurrent checkouts from claiming the same units.
- Reservation lifecycle is idempotent and records release/commit timestamps. Order/item snapshots stay independent from live catalog data.
- Current certified production database tracks are MySQL 8.4 LTS and MariaDB 10.11 LTS+ (11.4 LTS recommended); older legacy branches are not a design target.
