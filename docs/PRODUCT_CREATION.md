# Product creation model

The product editor is schema-driven and groups fields by business purpose rather than exposing one giant form.

Core groups:
- Identity: type, status, brand/manufacturer, internal identifiers, SKU/GTIN/MPN, condition, country of origin.
- Localized content: name, short description, full rich description, key benefits and language-specific content.
- Categories and publishing: primary/additional categories, stores, markets, scheduling.
- Pricing and tax: currency/price lists, compare-at/sale prices, quantity tiers, tax class, B2C/B2B presentation.
- Units: sale unit, minimum/maximum quantity, fractional step and unit pricing.
- Variants/options: color/size/etc. with variant-level SKU, GTIN, price, stock and media.
- Inventory: inventory item, warehouses, safety stock, backorder/preorder policy.
- Media: gallery, variant media, video/360-ready assets, alt text, focal point and responsive derivatives.
- Specifications: typed attributes with filtering/comparison flags.
- Shipping: physical/digital/service fulfillment, weight, package dimensions and carrier constraints.
- SEO: automatic/editable slug, title, description, canonical/indexing policy, redirects and hreflang.
- Google Commerce: taxonomy override, merchant product type/custom labels and canonical commerce projection.
- Compliance: manufacturer/economic operator, EU responsible person, warnings/safety information, regulatory identifiers.
- Documents: manuals, certificates, declarations, datasheets, warranty and safety files.
- Relations: related, accessories, alternatives, upsell, cross-sell, spare parts and frequently-bought-together links.
- Reviews/Q&A: product-level policy and storefront blocks; review content remains in its own domain tables.

The storefront Product Page Composer controls where these data blocks appear. It does not duplicate product data in page-builder JSON.
