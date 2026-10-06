# Changelog

## 3.89.0 — 2026-10-06

Customer groups with a real discount: the group price shows in the catalog and product page after sign-in and is taken off in the cart.

- New "Customer groups" admin page (code, name, discount %, "not for sale items" switch, customer count); the customer card and list now pick groups from it.
- A signed-in customer in a discounted group sees the regular price crossed out and the group price, in cards, product page and variants.
- The cart and checkout take the group discount off the order; items already on sale can be excluded per group.
- The cart page now also honours promotions limited to customer groups.
- Schema 83 (mc_customer_group).
