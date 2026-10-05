# Changelog

Nexora Commerce has a single product line on the `main` branch. Only release-level changes are listed.

## 3.64.0 — 2026-10-05

Customer options of a product.

- Product → Sales → Customer options: pick one (dropdown or radio buttons), pick several (checkboxes), text, long text, date, time, date and time; required or not; a default choice.
- Every choice can add to the price ("+"), take from it ("−") or set it ("="), and change the weight. Amounts are kept in the main currency of the store and shown in the currency the shopper chose, with the same rate, markup and rounding as the converted product prices.
- Product page: the price and the total follow the choices; the cart, the checkout and the order show them next to the product; the same product with different choices is a separate cart line; the weight counts in the delivery price per kilogram.
- The price effect of option values is also shown as a hint in the product form (main currency).
