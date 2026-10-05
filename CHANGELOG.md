# Changelog

Nexora Commerce has a single product line on the `main` branch. Only release-level changes are listed.

## 3.66.0 — 2026-10-05

Bot blocking and a faster way to fill meta tags.

- Admin → System → Bot blocking: ready lists of vulnerability scanners, site copiers, SEO crawlers and AI-training crawlers (each on its own switch), requests without a User-Agent, the shop's own User-Agent words and IP addresses or networks, a ready robots.txt block for AI crawlers, a check field to try a User-Agent, and daily counters. A blocked request gets a bare 403 before any work is done; payment and scheduler callbacks and search engines are never touched. Everything is built in and offline: no API keys or external services.
- Meta tags: every title and description field (products, categories, articles, pages, templates) has a compact counter with the recommended length, a correct example and {variable} chips ({name} {store} {price} {brand} {category} {sku}); variables typed into a title or description are filled in on the page, so one pattern can serve many items.
