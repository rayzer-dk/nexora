# Security audit baseline 3.0.4

This release turns the security checklist into repeatable gates rather than relying on manual review alone.

## Automated gates

`php bin/security-audit.php` checks forbidden execution primitives and direct request-to-SQL patterns.

`php bin/security-regression-check.php` exercises the outbound URL/SSRF policy and HMAC time/signature validation, and asserts that ZIP, image decompression/polyglot and HTTP header protections remain present.

`php bin/runtime-failure-injection-check.php` verifies fail-soft contracts for non-critical external dependencies and the centralized integration retry/dead-letter policy.

## Upload/package boundaries

Raster uploads accept only JPEG, PNG, WebP and AVIF after successful image decoding and dimension/pixel limits. The original binary is not published as the canonical derivative.

Product documents accept genuine PDF or plain text only. SVG/HTML/script/executable uploads are rejected.

Extension packages reject absolute/traversal paths, symlinks, hidden configuration payloads, unsupported file types, excessive file count, excessive per-file/total expanded size and suspicious compression ratios.

## SSRF boundary

Administrator/configuration supplied external application endpoints must be public HTTPS URLs. Loopback, private, reserved, link-local, localhost/local/internal hosts and embedded URL credentials are rejected. HTTP clients must keep redirects disabled or validate every redirect target before following it.

## Webhook replay

Payment callbacks are signature-verified first, then atomically claimed using a provider + SHA-256 fingerprint. Duplicate successful deliveries return success without applying payment state twice. A processing failure releases the claim so the payment provider may retry legitimately.

## Remaining dynamic penetration work

Production certification still requires runtime tests against a deployed build for CSRF, stored/reflected XSS, SQL injection, IDOR/broken access control, session fixation, CSP bypass, brute force/rate limits, request smuggling/proxy behavior, malicious PDF behavior and real reverse-proxy/web-server configuration.
