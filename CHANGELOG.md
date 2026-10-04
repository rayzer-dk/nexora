# Changelog

Nexora Commerce has a single product line on the `main` branch. Only release-level changes are listed.

## 3.47.0 — 2026-10-05

Storefront and admin polish release.

- Theme switch: a sun and a thin moon (storefront and admin); language menu text no longer blends into its hover background.
- One spacing standard: `--mc-section-gap` between page sections and `--mc-stack-gap` inside a block (home categories, benefits and coupon strip no longer touch).
- Product page: discount badge next to the price with the old price below, the unit ("pcs") sits outside the quantity box, coloured share icons without text plus a copy-link button with text, a real disabled button when the product is unavailable; carousels and "recently viewed" always show a button.
- Checkout: gift card and bonus points have "Apply" buttons with a live preview (`/checkout/rewards/preview`); the order summary stays in view on desktop.
- Catalogue: paging without a full reload (links keep working without script).
- Product attributes are no longer repeated once per language.
- Admin: quick status switch in the lists of categories, blog articles and blog categories; new "Demo data" page to install, refresh or remove the demo without a console (also the way to get the full demo after an update).
