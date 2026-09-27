# Modern web platform baseline

Transport and security:
- HTTPS only in production.
- TLS 1.3 preferred at the edge with safe compatibility fallback decided by the deployment layer.
- HTTP/2 baseline; HTTP/3/QUIC enabled when supported by reverse proxy/CDN.
- HSTS, CSP, Permissions-Policy, Referrer-Policy, secure/samesite cookies and CSRF protection.
- Brotli or Zstandard compression where client/server negotiation supports it; gzip fallback.
- Precompressed media is not recompressed.

Data and encoding:
- UTF-8 end-to-end; utf8mb4 in MySQL/MariaDB.
- JSON for general external APIs.
- GraphQL for flexible client queries.
- JSON-LD for Schema.org structured data.
- Webhooks for external event delivery with signature, idempotency key and retries.
- SSE for simple server-to-admin progress streaming; WebSocket only where two-way realtime is actually needed.
- WebTransport is not a baseline dependency because normal commerce flows do not benefit enough to justify complexity; the architecture leaves room for it later.

Search/discovery:
- Server-generated canonical URLs, hreflang and robots directives.
- Sitemap indexes and segmented sitemaps generated from canonical entities.
- Product/Offer merchant listing structured data emitted in initial HTML.
- Merchant shipping and return policy structured data supported by the SEO module.
- Open Graph and social metadata.
- Machine-readable product APIs and feeds.
- AI/agent-facing discovery endpoints are isolated behind a capability layer so new standards can be added without changing catalog internals.

Media:
- Original file retained.
- Responsive AVIF/WebP/JPEG derivatives.
- Width/height always emitted to prevent layout shift.
- srcset/sizes generated from media presets.
- SVG allowed only through a sanitizer and strict MIME/content validation.
