<?php

declare(strict_types=1);

return [
    'returns' => ['state' => 'static_ready', 'evidence' => ['commerce-operations'], 'runtime' => 'Create, approve, receive, refund and reject returns against real orders and payment providers.'],
    'gift_cards' => ['state' => 'static_ready', 'evidence' => ['rewards-commerce'], 'runtime' => 'Concurrent redemption, partial use, cancellation and refund on MySQL/MariaDB.'],
    'loyalty' => ['state' => 'static_ready', 'evidence' => ['rewards-commerce'], 'runtime' => 'Concurrent earning/redemption plus order cancellation and refund rollback.'],
    'compare' => ['state' => 'static_ready', 'evidence' => ['i18n','commerce-ux'], 'runtime' => 'Browser E2E for add/remove/clear and multi-store isolation.'],
    'saved_carts' => ['state' => 'static_ready', 'evidence' => ['i18n','commerce-ux'], 'runtime' => 'Authenticated browser E2E, concurrent updates and restore into a changed catalog.'],
    'b2b' => ['state' => 'static_ready', 'evidence' => ['b2b-commerce'], 'runtime' => 'Real approval flow, price lists, payment terms and purchase-order checkout.'],
    'forum' => ['state' => 'static_ready', 'evidence' => ['system-completeness','security-regression'], 'runtime' => 'Run browser E2E for topics, replies, moderation, permissions and multi-store isolation before Stable.'],
    'navigation' => ['state' => 'static_ready', 'evidence' => ['builder-navigation'], 'runtime' => 'Browser publish/reorder/visibility test across languages and stores.'],
    'page_builder' => ['state' => 'static_ready', 'evidence' => ['builder-navigation','xss-hardening'], 'runtime' => 'Browser authoring, revisions, publish and responsive rendering.'],
    'recommendations' => ['state' => 'static_ready', 'evidence' => ['recommendation-engine'], 'runtime' => 'Large-catalog relevance and latency test.'],
    'google_commerce' => ['state' => 'integration_pending', 'evidence' => ['backup-feed-seo'], 'runtime' => 'Real Merchant Center account/data-source diagnostics, rejection handling and retry lifecycle.'],
    'attribution' => ['state' => 'static_ready', 'evidence' => ['commerce-analytics'], 'runtime' => 'Consent-aware browser events and real order attribution across sessions.'],
    'abandoned_cart' => ['state' => 'static_ready', 'evidence' => ['marketing-automation'], 'runtime' => 'Cron/worker/email delivery, dedupe and recovery on a real database.'],
    'api' => ['state' => 'static_ready', 'evidence' => ['security-regression','public-api-contract','outbound-webhook'], 'runtime' => 'Read/write contracts, hashed tokens, scopes, store binding, rate limits, idempotent cart/checkout and signed outbound webhook delivery are implemented. Run real API/webhook E2E before Stable.'],
    'migration_center' => ['state' => 'integration_pending', 'evidence' => ['system-completeness'], 'runtime' => 'Validate large real OpenCart/ocStore databases including options, SEO, images, discounts, customers and orders.'],
    'developer_tools' => ['state' => 'static_ready', 'evidence' => ['developer-tools'], 'runtime' => 'Exercise diagnostics and failure paths against real infrastructure.'],
];
