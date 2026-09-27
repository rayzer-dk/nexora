# SEO URL architecture

SEO routing is a protected system capability and is independent from controller routes.

## Defaults

The initial store defaults are Ukraine (`UA`), Ukrainian (`uk-UA`), hryvnia (`UAH`) and `Europe/Kyiv`. The default Ukrainian locale has no URL prefix. Additional languages can use their configured `mc_store_locale.url_prefix` (for example `/en/`, `/de/`).

## Canonical URL rules

- A human-readable slug is generated automatically when a product, category, brand, CMS page, landing page or blog article is created.
- System pages always use stable English route words and never change with locale.
- Entity URLs prefer an available English localized title; if no English title exists yet, the current title is converted to clean ASCII transliteration. Native UTF-8 canonical slugs are disabled.
- Slugs are lowercase, words are separated with hyphens, `.html` is not appended, IDs are not exposed and tracking/session data never becomes part of a canonical path.
- Product/category/page canonical URLs are flat by default so moving a category does not change every descendant URL. Brand and blog articles use stable namespaces (`brand/` and `blog/`).
- The current path is globally unique within one store + locale. Collisions get a deterministic `-2`, `-3`, ... suffix.
- System routes such as `/admin`, `/api`, `/checkout`, `/cart`, `/assets`, `/graphql` and `/.well-known` cannot be claimed by content.

## Editing and redirects

The slug is editable in the entity SEO section. A change is transactional: the new canonical path is reserved and the previous path becomes a permanent server-side redirect to the same route record. Redirects point to the route ID, not another redirect, so repeated renames do not create chains.

Old paths stay reserved and cannot later be reused by another entity. The same entity may intentionally restore one of its own historical paths; the self-redirect is removed atomically. Historical order/catalog data never depends on the current slug.

## Multilanguage

Routes are scoped by store + BCP-47 locale. The locale prefix is resolved separately from the canonical entity path, so changing `/en/` to `/en-gb/` does not require rewriting every product slug. Canonical and hreflang links are generated from the same route registry and locale-prefix policy. Canonical path segments are ASCII-only, so storefront URLs do not expose percent-encoded Cyrillic or other Unicode path text.

## Search-engine rules

The platform follows the current Google Search guidance: descriptive human-readable paths, audience language/transliteration, hyphens rather than underscores, lowercase URLs, minimal parameters, server-side redirects, canonical consistency and language-specific URLs. Filter/sort/query variants do not create canonical entity routes.

## Source of truth

`mc_seo_route` is the canonical routing source. Legacy `slug` columns in translation tables are retained temporarily for migration/import compatibility during development but are not the long-term router source of truth.

## Redirect administration

Nexora keeps canonical-history redirects in `mc_seo_redirect`. The admin page `/admin/system/seo-redirects` shows the old path and the current canonical path side by side, including locale, entity type, reason, creation time, hit count and last-hit time.

Redirect history is created automatically when an editable canonical slug changes. Product, category and brand editing all use the same central SEO route registry. Translation imports that change a localized slug use the same mechanism.

Redirects resolve directly to the current `mc_seo_route` record. A second or third rename therefore does not create a redirect chain: every historical path resolves to the current route.

Old redirects can be deleted individually or in bulk by an administrator. Deletion is intentionally explicit because it removes the 301, makes the old path available for future reuse and can turn external links/bookmarks into 404 responses. There is no automatic expiry for canonical-history redirects.

Redirect hits are counted on a best-effort basis. Failure to update analytics never blocks URL resolution.

## OpenCart / ocStore migration

The migration adapter preserves available OpenCart `seo_url` / `url_alias` keywords for products, categories and manufacturers for every mapped active locale. When the source path differs from Nexora's canonical path (for example Nexora's `brand/` namespace), the source path is registered as a 301 alias to the imported canonical route.

OpenCart stores aliases, not a guaranteed snapshot of every public URL ever served by third-party SEO extensions. A custom SeoPro/SEO-module may have emitted hierarchical or otherwise rewritten URLs that cannot be reconstructed unambiguously from `seo_url` alone. Nexora therefore preserves every path that can be derived safely from source records and does not fabricate unknown legacy URLs. For such stores, provide an explicit legacy URL map during migration or import additional redirects after migration.
