# Changelog

## 3.96.1 — 2026-10-06

Release check fixes found while preparing the full archive.

- Landing forms: the CSRF token sits directly in each form, so the release static check (and any review) sees it.
- Admin header: the forum bell no longer shares the class of the customer-attention bell, so each can be found on its own.
