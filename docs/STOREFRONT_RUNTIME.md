# Storefront runtime — v2.5.0

The public storefront is now database-backed. Administration and storefront do not maintain separate product copies.

Flow:

`Admin product/category write -> publication -> central SEO route -> storefront query -> product/category page -> cart -> checkout delivery discovery`

## Publication

Only `published` products enabled for the active store and market are visible. Categories likewise require active store/market publication. A name change never silently changes an established URL. Explicit URL changes are handled by the central SEO layer and preserve a direct 301 alias.

## Cart

Anonymous carts use a cryptographically random browser token. Only its SHA-256 hash is persisted. The browser cookie is HttpOnly, SameSite=Lax and expires after seven days. Add/update/remove mutations lock the active cart and relevant cart row inside a transaction.

The cart validates sale unit, minimum, maximum, quantity step and current stock. Digital products do not require physical stock. Keeping an item in a cart does not reserve inventory; reservation belongs to checkout/order creation so abandoned carts cannot lock stock.

## Checkout boundary

`/checkout` consumes the real persistent cart. Physical fulfillment discovers cities first, then points/lockers for the selected carrier. Carrier directory failure is never a checkout-wide outage: the shopper can enter a delivery point/address manually and that value is marked unverified for later order handling.

Checkout now creates an immutable order snapshot, inventory reservation and idempotent payment record. Online card acquiring, Apple Pay/Google Pay authorization and refund webhooks remain intentionally disabled until a production acquiring provider is connected.

## Information pages

About, Contact, Shipping, Payment, Returns, Warranty, FAQ, Privacy Policy, Cookie Policy and Terms & Conditions are seeded as database records with stable `system_key` identities. Admin can edit them under `/admin/content/pages`. Empty/draft legal pages return a noindex maintenance response instead of publishing invented legal text.

## Media

Storefront catalog queries already understand product media relations, but production upload/derivative generation and optimized media delivery are not complete in v2.5.0. Products without a usable media asset receive the bundled product placeholder. Media Engine is the next dedicated layer after order/payment work or can be developed in parallel.
