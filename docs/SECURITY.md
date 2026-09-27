# Security baseline

Security is a protected core subsystem, not an optional plugin.

Application controls include CSRF protection, Sodium/Argon password hashing, server-side validation, HTML sanitization for rich content, strict security headers, per-route rate limiter definitions, payment/webhook idempotency, signed webhooks, audit/security events and secret values outside source control.

The Content Security Policy uses a per-request nonce for inline JSON-LD. HSTS is emitted on HTTPS responses. Public user-generated HTML is intended to pass through Symfony HTML Sanitizer before persistence/output.

Public forms use layered anti-spam: route rate limits, honeypot, minimum/maximum form lifetime, payload limits, duplicate/replay controls where relevant, then an optional risk-based Turnstile challenge. A normal customer should not see a CAPTCHA on every checkout.

Search engines and AI crawlers are not challenged on normal GET product/category/content pages. Bot protection is focused on state-changing actions and abusive traffic. Volumetric DDoS protection belongs at the reverse proxy/CDN/WAF layer, not in PHP.

The optional Turnstile verifier performs mandatory server-side verification. If Turnstile is disabled, local anti-abuse controls remain active.

Core update packages are accepted only after Ed25519 manifest verification and SHA-256 package verification, followed by compatibility preflight, backup, migration and health checks.

## Webhooks
Incoming webhooks use HMAC-SHA256 over `timestamp.raw_body`, constant-time comparison and a bounded timestamp window to reduce replay attacks. Provider-specific signature adapters can wrap the same contract when a vendor mandates another scheme.

## Bot protection availability
Turnstile is an optional step-up layer. Local rate limits, honeypots, form-age checks and payload limits remain active independently. A Turnstile transport/5xx outage is therefore fail-open for availability, while an explicit invalid token remains rejected.
