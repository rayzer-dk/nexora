# Network and transport security

Nexora Commerce is a PHP application. Encryption of the connection, HTTP/2 and HTTP/3, and protection from network attacks (DDoS) belong to the web server, the hosting provider or a CDN in front of the shop. The shop checks and reports them in **System → Network and security**, and sets everything that is its own job (headers, bot blocking, form traps).

## What the shop does itself

- `Strict-Transport-Security: max-age=31536000; includeSubDomains` on every https response.
- `Content-Security-Policy` with a per-request nonce, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-*` and frame protection.
- Bot blocking without a captcha for shoppers (System → Bot blocking), hidden form traps, an optional captcha, login throttling.
- PSR-4 autoloading: `Commerce\` → `src/`, `Commerce\Tests\` → `tests/` (see `composer.json`).
- Database queries are written with DBAL and bound parameters.

## TLS 1.3 and HTTP/2, HTTP/3 (nginx)

```nginx
server {
    listen 443 ssl;
    listen 443 quic reuseport;          # HTTP/3 (nginx 1.25+)
    http2 on;
    ssl_protocols TLSv1.2 TLSv1.3;      # TLS 1.3 preferred, 1.2 for old clients
    ssl_prefer_server_ciphers off;
    add_header Alt-Svc 'h3=":443"; ma=86400' always;
    # HSTS is added by the shop; do not duplicate it here.
}
```

Open UDP 443 in the firewall for HTTP/3. Apache 2.4.58+: `Protocols h2 http/1.1`, `SSLProtocol -all +TLSv1.2 +TLSv1.3`; HTTP/3 is easiest through a CDN.

## HSTS preload

Only after every sub-domain works over https, add the `preload` token at the web server and submit the domain to hstspreload.org. It is hard to undo, so the shop does not add it by itself.

## WAF and DDoS

A PHP application cannot absorb a network flood. Put the shop behind Cloudflare (or the provider's protection): enable the proxy (orange cloud), "Under Attack" mode on demand, managed WAF rules, and a rate limit rule for `/checkout`, `/account/login` and `/search`. The Network and security page shows "Cloudflare" once requests arrive through it. Restore real client addresses from `CF-Connecting-IP` with the trusted-proxy setting of the web server.

## E-mail: SPF, DKIM, DMARC

Publish three DNS records for the sender domain (the page shows which are found):

```
@                  TXT  "v=spf1 include:_spf.your-mail-service.com -all"
selector._domainkey TXT  "v=DKIM1; k=rsa; p=<public key from the mail service>"
_dmarc             TXT  "v=DMARC1; p=quarantine; rua=mailto:dmarc@your-domain.com"
```

Start DMARC with `p=none`, read the reports for a few weeks, then move to `quarantine` and `reject`.

## Web3

The core has no blockchain dependency. Crypto payments can be added as a payment extension (see `docs/EXTENSION_PROVIDER_CONTRACTS.md`).
