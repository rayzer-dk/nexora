# Changelog

## 3.95.0 — 2026-10-06

Cash register (PRRO) through Checkbox: fiscal receipts for paid orders.

- New "Cash register (PRRO)" page: Checkbox account (cashier login, password and license key stored encrypted), test or live environment, a connection check, automatic receipts for paid orders or manual only, receipt e-mail to the customer, payment methods that are skipped (bank transfer and B2B invoice by default).
- The order card shows the fiscal number and a link to the receipt, or a button to issue one by hand; one receipt per order, safe to retry.
- A scheduler task issues automatic receipts every 5 minutes; order discounts, gift card and bonus points are spread over the receipt lines so the receipt equals the payment.
- Tested against a stand-in of the Checkbox API; it has not been run against the live service, so check it on the test environment with your own keys first.
- Schema 87.
