#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Core/Platform/PlatformVersion.php';
$fail = [];

$must = [
    'migrations/Version20260927123000.php' => [
        'customer_id BIGINT UNSIGNED NULL',
        'mc_forum_subscription',
        'mc_forum_reaction',
        'mc_forum_report',
        'mc_forum_post_revision',
        'mc_forum_topic_read',
    ],
    'src/Modules/Forum/Http/ForumController.php' => [
        'CustomerUser',
        'storefront_forum_search',
        'storefront_forum_subscription',
        'storefront_forum_post_like',
        'storefront_forum_post_report',
        'storefront_forum_post_edit',
    ],
    'src/Modules/Forum/Application/ForumCommunityService.php' => [
        'setSubscription',
        'toggleLike',
        'reportPost',
        'editOwnPost',
        'memberStats',
        'openReports',
        'markRead',
        'unreadCount',
        'memberRecentActivity',
        'followedTopics',
    ],
    'src/Modules/Forum/Application/ForumService.php' => [
        "'customer_id' => \$customerId",
        'mc_forum_subscription',
        "'pin', 'unpin'",
    ],
    'src/Core/Site/SiteCapabilityAccessSubscriber.php' => [
        "str_starts_with(\$route, 'storefront_forum')",
        "return 'forum'",
    ],
    'themes/default/templates/forum/board.html.twig' => [
        "path('customer_login')",
        'forum_nickname',
    ],
    'themes/default/templates/forum/topic.html.twig' => [
        "storefront_forum_subscription",
        "storefront_forum_post_like",
        "storefront_forum_post_report",
        "storefront_forum_post_edit",
        'data-forum-quote',
    ],
    'assets/storefront/storefront-runtime.js' => [
        'data-forum-quote',
        'forumQuoteBody',
    ],
    'themes/default/templates/forum/search.html.twig' => [
        "storefront_forum_search",
    ],
        'src/Modules/Forum/Application/ForumProfileService.php' => [
        'show_email',
        'show_phone',
        'allow_private_messages',
    ],
    'src/Modules/Forum/Application/ForumDirectMessageService.php' => [
        'send(',
        'block(',
        'report(',
    ],
    'src/Modules/Forum/Application/ForumAccessPolicy.php' => [
        'email_verified_at IS NOT NULL OR phone_verified_at IS NOT NULL',
    ],
    'src/Modules/Customer/Application/CustomerVerificationCodeService.php' => [
        'requestCode',
        'verify(',
        'hash_hmac',
    ],
    'src/Modules/Forum/Application/ForumModerationService.php' => [
        'activeBans',
        'ban(',
        'revoke(',
    ],
    'themes/default/templates/forum/rules.html.twig' => [
        'forum_rules_title',
        'forum_rule_8_text',
    ],
    'src/Modules/Forum/Application/ForumNotificationService.php' => [
        'notifyPublishedReply',
        'NotificationOutbox',
    ],
    'src/Core/Module/SystemModuleCatalog.php' => [
        "\$optional('forum'",
        'ModuleRemovalPolicy::Removable',
    ],
];

foreach ($must as $file => $needles) {
    $text = @file_get_contents($root . '/' . $file);
    if (!is_string($text)) {
        $fail[] = 'missing ' . $file;
        continue;
    }
    foreach ($needles as $needle) {
        if (!str_contains($text, $needle)) {
            $fail[] = $file . ' missing ' . $needle;
        }
    }
}

foreach (['themes/default/templates/forum/board.html.twig', 'themes/default/templates/forum/topic.html.twig'] as $file) {
    $text = (string) @file_get_contents($root . '/' . $file);
    if (preg_match('/name=["\']author_name["\']/', $text) === 1) {
        $fail[] = $file . ' still permits free-form forum identity';
    }
}


foreach (['themes/default/templates/forum/board.html.twig', 'themes/default/templates/forum/topic.html.twig'] as $file) {
    $text = (string) @file_get_contents($root . '/' . $file);
    if (str_contains($text, 'app.user.displayName')) {
        $fail[] = $file . ' leaks commerce displayName into forum UI';
    }
}

$release = json_decode((string) @file_get_contents($root . '/resources/platform/release.json'), true);
foreach ([
    'forum-community-v2',
    'forum-database-antispam',
    'forum-rules',
    'forum-moderation-bans',
    'forum-unified-customer-identity',
    'forum-topic-subscriptions',
    'forum-post-reactions',
    'forum-user-reports',
    'forum-post-edit-history',
    'forum-search',
    'forum-member-profiles',
    'forum-follow-notifications',
    'forum-unread-tracking',
    'forum-post-pagination',
    'forum-quote-replies',
    'forum-removable-optional-module',
    'forum-privacy-nickname',
    'forum-contact-visibility-controls',
    'forum-private-messaging',
    'forum-member-blocking',
    'forum-private-message-reports',
    'forum-verified-participation',
    'customer-email-sms-verification-codes',
    'registration-turnstile',
] as $capability) {
    if (!in_array($capability, (array) ($release['capabilities'] ?? []), true)) {
        $fail[] = 'missing capability ' . $capability;
    }
}

if ((string) ($release['database_schema'] ?? '') !== (string) \Commerce\Core\Platform\PlatformVersion::DATABASE_SCHEMA) {
    $fail[] = 'release schema does not match PlatformVersion::DATABASE_SCHEMA';
}

if ($fail !== []) {
    fwrite(STDERR, "Forum Community v2 Check: FAILED\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}

echo "Forum Community v2 Check: OK\n";
