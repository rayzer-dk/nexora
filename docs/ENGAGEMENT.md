# Engagement tools (3.13.1)

## Floating contact buttons — Admin → Appearance → Contact buttons
One round button in the corner of every storefront page. It expands into the channels you filled in:

| Button | Source |
|---|---|
| Request a call | Small form (name + phone). The request is stored as an inquiry of type `callback` (Admin → Inquiries) and e-mailed to the store contact. |
| Write to us | `mailto:` of the address you set, otherwise the contact page form. |
| Call us | `tel:` of the phone you set, otherwise the store profile phone. |
| Viber | `viber://chat?number=…` (international number). |
| Messenger | `https://m.me/<page>` — a page name or an m.me / facebook.com link. |
| Telegram | `https://t.me/<user>` — a username or a t.me link. |

Values are validated on save (only the official hosts are accepted for Messenger/Telegram). The button lifts itself above the cookie banner and the mobile buy bar.

## Delivery regions in checkout
When at least one region of the customer's country is enabled (Admin → Shipping → Countries), checkout shows a required *Region* list. The server refuses an order without an enabled region and stores the region name in the shipment destination. A country without enabled regions is not restricted.

## IndexNow — Admin → System → IndexNow
Tells Bing, Yandex, Seznam and other participating engines about new and changed URLs (products, categories, brands, published articles and pages) without waiting for a crawl.

- The key is derived from `APP_SECRET` and the host; it is served as `https://host/<key>.txt`.
- Turn it on in the admin page; the cron task `indexnow` (every 30 min) sends the pages changed since the last successful run. **Submit all pages now** sends everything once.
- Requires a public https `APP_PUBLIC_URL` (not localhost, not an IP address). Google does not use IndexNow; keep `sitemap.xml` submitted in Search Console.
- CLI: `php bin/console commerce:indexnow:submit [--all]`.

## Share links
Articles and product pages have Facebook, Telegram, X, LinkedIn and *Copy link* (`components/share_links.html.twig`).

## E-mail templates — Admin → Notifications → E-mail templates
Per-language subject and text for: order received, order status changed, inquiry received, subscription confirmation. Placeholders such as `%order_number%`, `%customer_name%`, `%total%`, `%store_name%` are replaced when the e-mail is sent; the order table and the layout are unchanged. A missing or disabled template sends the built-in text. Templates apply to the store's primary language settings and to the recipient's order language when known.

## Live chat — Admin → Appearance → Contact buttons → Live chat
Supported services: Tawk.to, Jivo, Crisp, Chatwoot (self-hosted, https). Paste the embed code from the service dashboard or only the identifier (Tawk.to `propertyId/widgetId`, Jivo widget ID, Crisp Website ID, Chatwoot `websiteToken` + server address).

- No arbitrary scripts: the storefront builds the vendor URL itself from the validated ID.
- GDPR: the vendor script is requested only after the visitor allowed "Preferences" cookies in the cookie banner.
- CSP: `script/connect/frame/style/font-src` gain only the chosen service's domains, and only on pages that render the chat.
- The chat button in the contact list opens the chat; before consent it opens the cookie settings. When the chat is loaded the contact buttons move up so they do not overlap the vendor's launcher.
