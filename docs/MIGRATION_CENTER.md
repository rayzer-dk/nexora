# Migration Center

Migration Center imports legacy commerce data through read-only source adapters. It does not modify the source database and it does not use ad-hoc SQL copies into the target schema.

## OpenCart / ocStore 3.x support

The OpenCart 3 adapter currently reads languages/locales, currencies, manufacturers, categories, attributes, products, product translations, category relations, product images, options and option values, specials, discounts, SEO URLs, customers, orders, order items and order totals.

OpenCart options are preserved as normalized product options and option values. They are not converted into synthetic Cartesian variants because standard OpenCart option rows do not reliably describe real variant combinations.

Legacy customer password hashes are deliberately not imported. Imported customers use the platform recovery flow to establish a new password. Historic orders preserve immutable payment, shipping, address and legacy-status snapshots even when the referenced legacy product or customer cannot be mapped.

## Safety workflow

The supported workflow is:

Source scan -> Dry run -> Mapping -> Target conflict report -> Batched import -> Media transfer -> Reconciliation -> Publish

Dry-run detects malformed prices, missing names, duplicate SKU/GTIN, broken category parents, category cycles, missing relations, duplicate customer emails and historical references that cannot be mapped. Target conflict analysis reports existing target SKU/GTIN and customer-email reuse before apply.

The source database connection is opened read-only by convention and the importer executes only SELECT statements against it. Source credentials are supplied at runtime. The source password is not stored in Migration Center state.

## Idempotency and resume

`mc_migration_run` is the high-level run journal. `mc_import_job`, `mc_import_item`, `mc_import_id_map` and `mc_import_issue` are the detailed import ledger.

A stable non-secret `--source-key` identifies the source installation. Source entity IDs are mapped to target public IDs, so restarting the same run or importing the same source again does not create duplicate mapped entities. Resume is restart-safe: already mapped entities are revalidated and skipped, while unfinished entities continue through the same run ledger.

## Rollback

Rollback is scoped to one migration run and deletes only entities recorded as created by that run. Reused target records are never deleted. Products are removed before created attribute definitions when required by dependency order; orders are removed before customers/products.

Locales and currencies are global reusable registries and are intentionally retained during run rollback. Removing them automatically could break another store or existing target data.

## Media

Use `--image-root` to point at the legacy OpenCart image directory. Every source path is canonicalized with `realpath`; traversal outside the configured root is rejected. Missing or invalid files are reported without aborting unrelated catalog import.

## CLI

Use environment variables for source credentials:

```bash
export MIGRATION_SOURCE_DB_USER='legacy_readonly'
export MIGRATION_SOURCE_DB_PASSWORD='...'

php bin/console commerce:migration:opencart \
  --dsn='mysql:host=127.0.0.1;dbname=legacy_shop;charset=utf8mb4' \
  --prefix='oc_' \
  --source-key='legacy-shop-prod' \
  --store=1 \
  --market=1 \
  --locale='uk-UA' \
  --currency='UAH' \
  --locale-map='uk-ua=uk-UA' \
  --locale-map='ru-ru=ru-RU' \
  --image-root='/var/www/legacy/image/catalog'
```

Without `--apply` the command performs dry-run only and does not change target commerce data. Add `--apply` after reviewing the report. `--publish-products` is explicit; otherwise imported products remain drafts.

Resume:

```bash
php bin/console commerce:migration:opencart ... --apply --resume='<run-uuid>'
```

Rollback does not require source credentials:

```bash
php bin/console commerce:migration:opencart --rollback='<run-uuid>'
```

The admin Migration Center page shows run history, progress, issue counts and exposes CSRF-protected rollback. Large imports remain CLI-driven so they do not depend on HTTP request timeouts.

## Production note

A full backup of the target database and media storage is required before an actual migration. Dry-run and a staging migration should be completed before importing into a public store.
