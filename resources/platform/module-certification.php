<?php

declare(strict_types=1);

return [
    'returns' => ['state' => 'production_certified', 'evidence' => ['commerce-operations'], 'runtime' => 'Return lifecycle contracts cover create, approve, receive, refund and reject flows against order/payment services.'],
    'gift_cards' => ['state' => 'production_certified', 'evidence' => ['rewards-commerce'], 'runtime' => 'Gift-card redemption, partial use, cancellation and refund invariants are certified by rewards-commerce and database runtime QA.'],
    'loyalty' => ['state' => 'production_certified', 'evidence' => ['rewards-commerce'], 'runtime' => 'Loyalty earn, redeem, cancellation and refund rollback invariants are certified by rewards-commerce and database runtime QA.'],
    'compare' => ['state' => 'production_certified', 'evidence' => ['i18n','commerce-ux'], 'runtime' => 'Comparison add/remove/clear behavior, localization contracts and store-scoped runtime paths are covered by release QA.'],
    'saved_carts' => ['state' => 'production_certified', 'evidence' => ['i18n','commerce-ux'], 'runtime' => 'Authenticated saved-cart persistence, restore behavior and catalog-change handling are covered by release QA contracts.'],
    'b2b' => ['state' => 'production_certified', 'evidence' => ['b2b-commerce'], 'runtime' => 'Approval thresholds, price tiers, payment terms and purchase-order checkout contracts are certified by b2b-commerce QA.'],
    'forum' => ['state' => 'production_certified', 'evidence' => ['system-completeness','security-regression'], 'runtime' => 'Topics, replies, moderation, permissions and store isolation are covered by system-completeness and security regression contracts.'],
    'navigation' => ['state' => 'production_certified', 'evidence' => ['builder-navigation'], 'runtime' => 'Publish, reorder, parent-cycle protection and visibility contracts are certified by builder-navigation QA.'],
    'page_builder' => ['state' => 'production_certified', 'evidence' => ['builder-navigation','xss-hardening'], 'runtime' => 'Builder revisions, snippets, publication contracts and XSS hardening are certified by release QA.'],
    'recommendations' => ['state' => 'production_certified', 'evidence' => ['recommendation-engine'], 'runtime' => 'Recommendation modes, settings and catalog projection contracts are certified by recommendation-engine QA.'],
    'google_commerce' => ['state' => 'production_certified', 'evidence' => ['backup-feed-seo','google-commerce-integration'], 'runtime' => 'Merchant API insert/delete/diagnostics contract, auth, error propagation, retry backoff and dead-letter lifecycle are CI-certified; optional live-account smoke remains operational validation.'],
    'attribution' => ['state' => 'production_certified', 'evidence' => ['commerce-analytics'], 'runtime' => 'Consent-aware marketing event and order attribution contracts are certified by commerce analytics QA.'],
    'abandoned_cart' => ['state' => 'production_certified', 'evidence' => ['marketing-automation'], 'runtime' => 'Recovery token, dedupe, queue and automation invariants are certified by marketing automation QA.'],
    'api' => ['state' => 'production_certified', 'evidence' => ['security-regression','public-api-contract','outbound-webhook'], 'runtime' => 'Hashed tokens, scopes, store binding, rate limits, idempotency and signed webhook delivery are certified by API/security contract QA.'],
    'migration_center' => ['state' => 'production_certified', 'evidence' => ['system-completeness','migration-opencart-runtime'], 'runtime' => 'OpenCart/ocStore schema fixture is executed against real MySQL 8.4 and MariaDB 11.4 with multilingual SEO, images, options, discounts, customers, orders and dry-run integrity validation.'],
    'developer_tools' => ['state' => 'production_certified', 'evidence' => ['developer-tools'], 'runtime' => 'Diagnostic routes, permission boundaries and prohibited execution paths are certified by developer-tools QA.'],
];
