# Product badges, delivery countries, editor

## Product badges (Admin → Catalog → Product badges)
- Built-in automatic rules: **Sale** (compare-at price set), **New** (published within N days, default 14) and **Bestseller** (at least M units sold in N days, default 5 in 30). Each rule can be disabled, recoloured, renamed per language and reprioritised.
- Custom badges: create a code, text, colour and a list of SKUs; all products behind those SKUs get the badge.
- A card shows up to three badges ordered by priority (lower first). The product page shows the same badges above the title.
- Storefront catalogue queries are cached for 8 seconds, so a change is visible within that time.
- Table `mc_product_badge`, `mc_product_badge_product`; service `ProductBadgeService`. A store without saved rules uses the built-in defaults.

## Delivery countries and regions (Admin → Shipments → Delivery countries)
- Tick the countries you deliver to (all ISO countries, names localised through ICU). While no list is saved, delivery is unrestricted. Once saved, checkout for the market country is refused unless the country is enabled.
- Regions (oblasts, voivodeships, …) are stored per country in `mc_shipping_region`; they can be added, enabled, disabled, deleted, and default lists for UA, PL, DK are imported from `resources/data/shipping-regions.json`.
- Regions are a managed catalogue; the checkout form does not yet ask for a region.

## Rich text editor
Bold, italic, underline, strikethrough, H2/H3, lists, quote, rule, table, image by URL, link, clear formatting, undo/redo and an **HTML** button that toggles a source view. Markup is always sanitised on the server (`commerce.rich_text`).

## Confirmation dialogs
Every destructive admin form (`delete`, `disable`, `uninstall`, `restore`, `rollback`, `revoke`, `purge`, …) must carry `data-confirm`; `bin/admin-confirm-check.php` fails the release otherwise. A confirmation on a `<form>` only intercepts its submit buttons.

## Product documents and certificates
Documents carry a type (certificate, manual, SDS, datasheet, warranty); the product page lists them with the type label.
