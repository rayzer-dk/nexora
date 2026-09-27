# Backup, catalog feeds and sitemap

## Backup Center

The admin Stability screen provides three backup profiles:

- database: consistent database snapshot only;
- data: database plus `public/media`;
- full: database, media, managed application files, extensions/themes and vendor when available.

Archives stay under `var/recovery` and can be downloaded to a workstation. Database/data profiles may be restored from the admin UI after CSRF validation; the platform creates a full pre-restore safety backup first. Full application restore remains an emergency CLI operation so a running PHP process never replaces its own application/vendor tree.

CLI backups support the same profiles through `commerce:recovery:snapshot --backup-profile=database|data|full`.

## Catalog import/export

The product CSV pipeline exports/imports the operational catalog fields including SKU, product name/status/type, price/currency/stock, sale unit, GTIN, MPN, brand id/name, category ids, SEO slug, short description and full description. Apply imports use ProductWriter rather than direct mass SQL updates so catalog validation and domain events remain active.

## Feed Center

Feed Center uses one canonical product/variant projection for price, stock, URL, media, brand, GTIN/MPN, taxonomy and attributes. Supported snapshots:

- Google Merchant RSS/XML;
- Meta/Facebook catalog CSV;
- Pinterest catalog CSV;
- TikTok catalog CSV;
- Rozetka XML;
- Prom.ua YML;
- generic CSV and JSON.

Variants are exported as separate offer/item ids and share an item/group id. Marketplace-specific category mappings are stored separately from the store taxonomy. Invalid marketplace items are skipped with an explicit warning instead of emitting malformed records.

Feeds are written atomically under `var/feeds`; public feed URLs only serve the latest completed snapshot and never regenerate a large feed inside a crawler HTTP request. Refresh manually in Feed Center or schedule:

`php bin/console commerce:feeds:generate --platform=all`

## Sitemap

`/robots.txt` advertises `/sitemap.xml`. The sitemap index is split by active store and locale, and each child sitemap is paged at 20,000 canonical indexable SEO routes. Products, categories, brands, CMS pages, blog articles and landing pages are included only through the canonical SEO route table; non-indexable routes are excluded.

Platform serializers are independent: Google uses its RSS namespace/value conventions, Pinterest uses retail-catalog CSV fields, and TikTok Product Catalog uses `sku_id` plus its catalog availability vocabulary. The data source remains canonical while output syntax stays platform-specific.
