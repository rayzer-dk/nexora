# Changelog

Nexora Commerce has a single product line on the `main` branch. Only release-level changes are listed.

## 3.52.0 — 2026-10-05

Orders, own delivery and payment methods, flags.

- Order card: customer name, phone, e-mail and delivery address can be corrected; the order is marked "edited" and the event log keeps the old and new values.
- Order card: "Write to the customer" — a message (and up to 5 files) is put into the shop e-mail design with a short order summary; the button is active only when the customer has an e-mail.
- Delivery and payment methods: add your own methods (courier, own delivery, invoice, card transfer) with icon, names per language, fee, free-over amount and limits; own payment methods are marked as paid in the order card.
- Language tabs show flags.
- Cart: progress to free delivery.
- Subscribers: add or import a list (text or CSV) with a consent confirmation.
