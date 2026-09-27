# SEO indexing policy

The central SEO router is the only source of canonical entity URLs. Runtime query state does not create permanent SEO routes.

Default category policy:
- canonical category: index, follow;
- pagination page 2+: index, follow with self-canonical page URL;
- sorting, page-size and view-mode variants: noindex, follow and canonical to the clean category;
- runtime filters/facets: noindex, follow and canonical to the clean category;
- empty filter combination: HTTP 404;
- tracking parameters are removed from canonical decisions;
- valuable filter combinations may be explicitly promoted to `mc_seo_facet_landing`, receive a clean static route, unique metadata/content and become indexable;
- only canonical first-page routes and deliberate SEO landing pages enter the sitemap.

Filter UI should use controls/forms rather than emitting crawlable links for every possible combination. This keeps shareable filter state possible without generating an infinite internal-link graph.

Every localized canonical set emits reciprocal hreflang values and an x-default fallback. The default implementation points x-default to the store default locale unless a neutral country/language selector is configured.
