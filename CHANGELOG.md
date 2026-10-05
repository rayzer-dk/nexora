# Changelog

Nexora Commerce has a single product line on the `main` branch. Only release-level changes are listed.

## 3.71.0 — 2026-10-05

Checkout keeps the shopper's input; a new report on network and mail security.

- A rejected order (an expired checkout session, an invalid security token, a blocked country, a refusal by the shop) returns to the checkout with the name, phone, e-mail, delivery and payment choices still filled in. An expired security token no longer ends in an error page.
- New page System → Network and security: HTTPS, TLS version, HTTP version, HSTS and a protective proxy in front of the site, plus SPF, DKIM and DMARC records of the sender domain read from DNS.
- New guide `docs/NETWORK_SECURITY.md`: TLS 1.3, HTTP/2 and HTTP/3, HSTS preload, WAF and DDoS protection, e-mail records.
