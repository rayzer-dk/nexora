# Changelog

Nexora Commerce has a single product line on the `main` branch. Only release-level changes are listed.

## 3.61.0 — 2026-10-05

Feed rules per channel, SMS statistics and retry.

- Trade feeds: rules per channel — markup or discount in percent, a price corridor, categories and brands left out, "only in stock". Shop prices are never touched; left-out items are counted as skipped.
- SMS: statistics for the last 30 days (sent, failed, parts) and a "Retry" button for failed messages; a retried message is marked as such.
- Audit of pages, blog, tax, captcha, AI and cron: complete, no gaps found.
