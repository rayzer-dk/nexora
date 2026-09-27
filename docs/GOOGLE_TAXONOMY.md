# Google product taxonomy

`google_product_category` and the store's own category tree are different concepts.

Nexora Commerce keeps the shop category hierarchy as the source of navigation and `product_type`. A Google Product Category may be attached to a store category as a default mapping and may be overridden for an individual product.

Resolution order:
1. explicit per-product Google category override;
2. primary store category Google mapping;
3. no submitted value, allowing Google automatic classification.

A Google category is not mandatory for every product because Google automatically classifies products. Manual mapping is used when the merchant wants to correct or make classification more specific.

The platform never treats Google taxonomy IDs as internal category IDs. Taxonomy data is versioned/cacheable integration data so a taxonomy update cannot restructure the store catalog.

`product_type` is generated from the merchant's own category path and remains separate from `google_product_category`.

Merchant API currently exposes the merchant-provided `google_product_category` value; it does not provide the automatically assigned Google category back through the API. Therefore an empty local mapping is a deliberate state, not missing data.
