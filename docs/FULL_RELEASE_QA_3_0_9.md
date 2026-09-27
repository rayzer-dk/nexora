# Full Release QA 3.0.9

This QA pass concentrates on real install/update/storefront/admin risks rather than adding features.

## Verified autonomously in the SOURCE workspace

- PHP syntax, JSON metadata, static security, security regression, SEO, immutable rollback and runtime failure-isolation gates.
- Browser installer renders standalone without PHP fatal errors and preserves its preflight behavior when vendor/database are unavailable.
- Static route contract: storefront/admin journey routes exist and Twig route references resolve.
- POST form contract: storefront/admin POST forms expose CSRF tokens.
- Notification contract: email sender, notification outbox worker, order subscriber and generic/order-created/order-status templates are present.
- Chromium responsive simulation: 66 templates/major blocks at 1440, 1024, 768 and 390 px (264 cases) with no horizontal document overflow after the 3.0.9 fixes.
- Chromium interaction simulation: quantity increment, sticky purchase submit, checkout carrier switching, admin mobile sidebar, command palette and confirmation modal.

## Update model

Do not overwrite a running installation with arbitrary source files. Core update is staged and validated, takes a verified recovery snapshot, applies pre-generated migration SQL while maintenance mode protects traffic, atomically switches the release, runs a private HTTP smoke probe, and rolls back on failure. New installation and update therefore use different safe workflows.

## Still requires a real release environment

The SOURCE workspace has no Composer vendor tree or running MySQL/MariaDB server, so it cannot truthfully certify runtime installation, database migrations, SMTP transport, payment/shipping providers or complete BrowserKit/real-browser E2E against a live Symfony application. Those remain mandatory PRODUCTION-package gates on PHP 8.4/8.5 with MySQL and MariaDB.
