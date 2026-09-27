# Marketing Hub

Marketing Hub consumes immutable commerce domain events through the integration queue. It does not run provider HTTP requests inside checkout.

First-party adapters:
- GA4 Measurement Protocol;
- Meta Conversions API;
- TikTok Events API.

All providers are OFF by default and require explicit environment configuration.

## Consent

Every queued customer event must carry a consent snapshot captured at the business event boundary. Analytics delivery requires analytics consent. Advertising delivery requires marketing/ad consent. Missing consent is deny-by-default and produces `skipped_no_consent`; it is not treated as a transport error and is never retried into a later implicit consent state.

Provider event delivery is deduplicated by provider + immutable event ID. Queue retries therefore cannot intentionally produce duplicate server-side conversions.

Raw secrets are never placed into queue payloads. Meta/TikTok adapters hash normalized email/phone identifiers before transport. Checkout secrets, passwords and tokens are removed from analytics parameter projection.

## Reliability

Run:

`php bin/console commerce:integration:work --limit=100`

The worker claims jobs transactionally, retries transient failures with exponential backoff and stops retrying after eight attempts. Dead jobs stay inspectable in `/admin/system/integrations` and require an explicit admin retry.

Analytics/advertising failure cannot fail checkout, order creation, payment or catalog writes.
