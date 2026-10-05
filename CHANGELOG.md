# Changelog

Nexora Commerce has a single product line on the `main` branch. Only release-level changes are listed.

## 3.74.0 — 2026-10-05

The category list is a real tree, and every top-level category reaches the storefront.

- Admin: the category list expands and collapses (one chevron per branch, "expand all" and "collapse all", the state is remembered) and categories are moved by drag and drop: onto another category to make it a subcategory, between rows to change the order, onto the "top level" strip to make it a main category. Moving never makes a loop and renumbers the siblings.
- Storefront: the header category bar showed at most 8 top-level categories and skipped those without a translation in the current language; the home category tiles skipped them too. Both now show every active top-level category, in the shop's default language when a translation is missing.
