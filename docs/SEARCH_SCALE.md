# Search and storefront scale profile

SQL search is the canonical fallback and remains the default for small/medium stores. `MEILISEARCH_ENABLED=0` is therefore the safe fresh-install state.

For larger catalogs, Meilisearch can be enabled as a read accelerator. It never owns prices, inventory or checkout state. Storefront search asks Meilisearch only for ranked candidate product IDs and continues to apply authoritative store/market/price/stock/filter rules in MySQL/MariaDB. If Meilisearch is disabled, unhealthy or times out, the same request falls back to the SQL search path.

Configuration:

`MEILISEARCH_ENABLED=1`
`MEILISEARCH_ENDPOINT=http://127.0.0.1:7700`
`MEILISEARCH_API_KEY=...`
`MEILISEARCH_INDEX=commerce_products`

Initial/recovery indexing:

`php bin/console commerce:search:reindex --batch=250`

The external index is rebuilt from `mc_storefront_product_projection`. Catalog product events update it incrementally. Order placed/cancelled events refresh affected product projections so availability does not remain stale after reservation changes.

Deep catalog traversal should prefer `/api/storefront/catalog/cursor` instead of very large `page=N` offsets. The SEO HTML pagination remains available for normal page depths.

Popular facet payloads can be precomputed without making them mandatory:

`php bin/console commerce:storefront:warm-facets --categories=40 --ttl=300`

If the projection is missing or expired, the storefront computes facets from canonical SQL and continues normally.
