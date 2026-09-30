# Dashboard, automation, push, downloads, custom fields (3.15.0)

## Dashboard
`DashboardService::build(storeId, days)` returns one report (KPIs with a delta against the previous period of equal length, daily series in the store timezone, goals, funnel, pipeline, top products, restock hints, sources, zero-result searches, recent orders, inquiries). Revenue counts only paid orders (`paid`, `partially_refunded`, `refunded`) minus refunds. `DashboardChart::layout()` turns the series into SVG geometry; notes are stored in `mc_dashboard_annotation`, month goals in `mc_dashboard_goal`.

Quick orders (`mc_customer_inquiry.inquiry_type = quick_order`) are inquiries, not carts. They are never counted as abandoned carts; the funnel shows them on a separate line and the dashboard offers "Create order" for each.

## Manual orders
`ManualOrderService` builds a cart from SKUs and calls `CheckoutOrderService::place()` with per-line price overrides. Only this trusted caller can override prices. A failed order removes the temporary cart. From an inquiry (`?inquiry=ID`) the inquiry becomes `resolved` and keeps the order number in its note.

## Automation
Events come from the domain-event outbox (orders, customers) and from direct hooks (inquiry, review, return). `mc_automation_run` has a unique key on (rule, event reference), so a retried event never fires twice. Webhook targets pass `OutboundUrlPolicy::assertPublicHttps`, use a 3 s timeout and follow no redirects.

## Web push
`WebPushCrypto` implements VAPID (ES256 JWT) and RFC 8291 `aes128gcm`; `WebPushCryptoTest` checks the RFC 8291 appendix vector byte for byte. Endpoints must be public https (SSRF policy). 404 / 410 removes a subscription; five consecutive failures remove it too. The VAPID private key is encrypted with `SecretVault`.

## Captcha
`login` is a captcha form for the customer login (a `kernel.request` listener, because the firewall handles the POST). Admin login has no captcha on purpose. `fail_mode` = `open` (default) or `closed` applies to Google / Turnstile on a transport error or a 5xx reply. The rate limit and honeypot are unaffected.

## Downloads and custom fields
Uploads are checked by extension, real MIME and magic bytes, then stored by content hash under `public/media/downloads`. Custom fields live in `mc_custom_field_definition` / `mc_custom_field_value`; number, link and date values are validated and invalid values are dropped.
