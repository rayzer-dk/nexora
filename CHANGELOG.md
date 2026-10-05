# Changelog

Nexora Commerce has a single product line on the `main` branch. Only release-level changes are listed.

## 3.62.0 — 2026-10-05

Guest reviews, storefront search by sound and keyboard layout, admin quick buttons, bot traps on account forms.

- Reviews and product questions can be left without an account (name and e-mail, always moderated first; switch in Customer experience → Reviews). Guests are limited per client; a guest who bought with the same e-mail gets the "verified purchase" mark; replies go to the guest's e-mail.
- Storefront search now uses the built-in index: names in every store language, brand, categories, SKU; word forms, one or two typos, the other alphabet ("самсунг" finds Samsung) and the wrong keyboard layout ("ыфьыгтп").
- Admin search (Ctrl+K): all words must match, finds by page address too, tolerates a typo, the other alphabet and the wrong layout.
- Quick buttons: every administrator can put up to six icon buttons for any page they may open into the header (user menu → Quick buttons).
- Bot traps: the hidden field and the "sent too fast" check now also guard the login, registration, password recovery and withdrawal forms; the built-in captcha picture is warped.
- Fixed: reviews and questions could not be sent at all (the product was looked up with a status it never has) and the quality counters on the dashboard counted zero products for the same reason.
