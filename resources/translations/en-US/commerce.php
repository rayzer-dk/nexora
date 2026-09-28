<?php

declare(strict_types=1);

return [
    'ai.product_draft.failed' => 'The AI could not prepare a draft. Check the provider settings.',
    'ai.product_draft.prompt' => 'Write a professional short description and a main HTML description in Ukrainian for an online store product. Do not invent specifications that are not in the input data. Return ONLY valid JSON with no markdown in the format {"short_description":"...","description":"<h2>...</h2>..."}. Name: %name%. SKU: %sku%. Current description/facts: %current%.',
    'ai.product_draft.system' => 'You are an e-commerce content editor. Do not invent facts. HTML with no script, style, or H1.',
    'catalog.boolean_no' => 'No',
    'catalog.boolean_yes' => 'Yes',
    'catalog.in_stock' => 'In stock',
    'catalog.out_of_stock' => 'Out of stock',
    'content.information_page.about.title' => 'About us',
    'content.information_page.contacts.title' => 'Contacts',
    'content.information_page.cookies.title' => 'Cookie policy',
    'content.information_page.delivery.title' => 'Shipping',
    'content.information_page.faq.title' => 'FAQ',
    'content.information_page.payment.title' => 'Payment',
    'content.information_page.privacy.title' => 'Privacy policy',
    'content.information_page.returns.title' => 'Returns and exchanges',
    'content.information_page.terms.title' => 'Terms and conditions',
    'content.information_page.warranty.title' => 'Warranty',
    'customer.inquiry.manager_text' => 'Name: %name%
Phone: %phone%
Email: %email%%product%

%message%',
    'customer.inquiry.product_line' => '
Product: %product%',
    'customer.inquiry.received_subject' => 'We received your inquiry',
    'customer.inquiry.received_text' => 'Thank you. Your inquiry has been received, a manager will respond shortly.',
    'theme.footer.company.title' => 'Company',
    'theme.footer.help.title' => 'Customer service',
    'theme.footer.legal.title' => 'Legal',
    'shipping.error.provider_required' => 'A shipping provider must be specified.',
    'storefront.error.store_not_mapped' => 'No active store is configured for this domain.',
    'storefront.error.market_not_configured' => 'No active market is configured for the store.',
    'scheduler.error.console_unavailable' => 'The console application is unavailable.',
    'security.webhook.invalid_replay_key' => 'Invalid webhook replay-protection key.',
    'scheduler.label.notifications' => 'Email / Telegram / SMS',
    'scheduler.label.integrations' => 'Google / Marketing',
    'audit.localization.internal_exception_debt' => 'Internal technical exceptions still contain English-language diagnostic messages; they must not be surfaced directly to HTTP/UI.',
    'scheduler.label.webhooks' => 'Webhook API',
    'scheduler.description.webhooks' => 'Delivers signed outbound webhook events with retries and failure isolation.',
    'scheduler.label.currency_prices' => 'Exchange rates and currency prices',
    'scheduler.description.currency_prices' => 'Fetches official NBU exchange rates and recalculates prices for auto-converted currencies. Explicitly set prices are not changed.',
    'scheduler.label.retention' => 'Technical data cleanup',
    'scheduler.description.retention' => 'Once a day, removes expired tokens, old guest carts, search and security logs, delivered webhooks, old automatic pre-update snapshots, update leftovers, and expired cache. Orders, customers, catalog, and manual backups are not affected.',
];
