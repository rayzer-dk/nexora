# Changelog

## 3.93.0 — 2026-10-06

Product controls (group prices, quantity rules, hide, reviews, reward points), an SEO rules hint, and a sweep that found and fixed a broken customer card.

- Product editor: price per customer group ("vip=250"), minimum / step / maximum quantity, hide from catalog and search (page stays reachable, kept out of the index), turn reviews off per product, reward points percent per product.
- The customer card in the admin returned an error for every customer (inquiry count used a missing column); fixed.
- A "?" hint with short rules for H1, title and description next to the SEO fields of products, categories and articles.
- New end-to-end sweep: every storefront page (desktop and phone) and admin page is visited and fails on console errors, failed requests or sideways scroll.
- Schema 86.
