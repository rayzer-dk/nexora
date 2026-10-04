# Changelog

Nexora Commerce has a single product line on the `main` branch. Only release-level changes are listed.

## 3.48.0 — 2026-10-05

Admin and storefront polish, part two.

- New design of the update page and of the 404 page (big code, search, illustration).
- Order page: statuses, delivery, carriers, events and units are shown in words; quick view redesigned; a click on a table row opens the record.
- Header: counter of new returns, reviews, questions and withdrawal notices; the top bar stays on one line.
- Long admin pages become tabs, with an "All sections" switch (remembered); Storefront, Brands and attributes, Marketing and any page with four or more panels.
- File manager (media picker): icon toolbar with tooltips, folders with path and "up", new folder, upload from the computer, last folder remembered; product photos use one toolbar.
- Abandoned carts first on the marketing page: customer, phone, items, total, time and reminder status.
- E-mail templates and lifecycle e-mails can be written in HTML; the order e-mail shows product photos.
- Badges: colour by swatches or HEX, one text per store language.
- Benefit cards of the home page are editable: icon, title and text per language, devices, number on a phone; the row has no empty cells.
- Support chat: delay in seconds before the chat script is loaded.
- Product, category and article open in a language without a translation (empty fields instead of an error).
- Shorter demo SKUs; default mail goes from the server itself (`native://default`) with an address on the shop's domain.
