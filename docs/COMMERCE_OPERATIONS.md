# Commerce operations

Version 3.1.0 closes the minimum operational commerce gaps without making optional services mandatory.

## Promotions and coupons

Automatic promotions and coupon codes are calculated server-side. AJAX preview is advisory only; checkout recalculates promotion eligibility and discount inside the order transaction. Promotion rows are locked during authoritative checkout calculation so usage limits cannot be overrun by concurrent orders.

## Bulk actions and CSV

Product bulk status changes and CSV import use the normal ProductWriter path. They do not bypass validation, domain events, search indexing or integration synchronization. CSV supports preview before apply. Export is UTF-8 CSV.

## Notifications

Email and Telegram use the durable notification outbox. SMS is optional and disabled by default. The generic SMS HTTPS gateway validates public HTTPS destinations through the outbound URL policy. A disabled SMS sender fails explicitly instead of reporting a false delivery success.

## Newsletter and campaigns

Storefront subscriptions use double opt-in. Unsubscribe URLs are signed. Campaign creation is fast: the admin request only creates a queued campaign. Run the batch fan-out command periodically:

`php bin/console commerce:campaigns:enqueue --batch=250 --max-batches=20`

The normal notification worker then performs actual email delivery. Batch fan-out is transactional and uses durable dedupe keys.

## AI authoring

OpenAI and Gemini authoring helpers are optional and OFF by default. API keys are environment-only. AI can prepare a product-description draft from the admin product form, but it never saves the product automatically; normal product Save remains authoritative.

## Release gate

`php bin/commerce-operations-check.php`

The gate protects the key contracts above from regression.
