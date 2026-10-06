# Changelog

## 3.96.0 — 2026-10-06

Return receipts for the cash register (PRRO).

- A succeeded refund gets a return receipt in Checkbox, linked to the sale receipt: a full refund returns every line of the order, a partial one returns the refunded amount.
- The order card shows the return receipt number per refund, or a button to issue it by hand; the scheduler issues them automatically when automatic receipts are on. A sale receipt is required first.
- New end-to-end checks for the return receipt and for a minimum quantity with a multiple (from 3 pieces, in threes).
- Tested against a stand-in of the Checkbox API; check it on the Checkbox test environment with your own keys before going live.
- Schema 88.
