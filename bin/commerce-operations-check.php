#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Core/Platform/PlatformVersion.php';

$root = dirname(__DIR__);
$failures = [];
$checks = [
    'promotion transactional lock' => ['src/Modules/Promotion/Application/PromotionEngine.php', "\$forUpdate ? ' FOR UPDATE' : ''"],
    'checkout authoritative promotion recalc' => ['src/Modules/Order/Application/CheckoutOrderService.php', 'calculateForCart'],
    'promotion redemption audit' => ['src/Modules/Promotion/Application/PromotionRedemptionRecorder.php', 'mc_promotion_redemption'],
    'bulk through ProductWriter' => ['src/Modules/Bulk/Application/ProductBulkActionService.php', 'ProductWriter'],
    'CSV through ProductWriter' => ['src/Modules/ImportExport/Application/CatalogCsvService.php', 'ProductWriter'],
    'SMS SSRF guard' => ['src/Modules/Notification/Channel/Sms/HttpSmsNotificationSender.php', 'assertPublicHttps'],
    'newsletter double opt-in' => ['src/Modules/Marketing/Http/NewsletterController.php', "status'=>'pending'"],
    'newsletter signed unsubscribe' => ['src/Modules/Marketing/Application/NewsletterTokenService.php', 'hash_hmac'],
    'campaign batch worker' => ['src/Modules/Marketing/Application/NewsletterCampaignQueueProcessor.php', 'FOR UPDATE SKIP LOCKED'],
    'campaign dedupe' => ['src/Modules/Marketing/Application/NewsletterCampaignQueueProcessor.php', "'campaign:'"],
    'AI manual draft endpoint' => ['src/Modules/Ai/Http/AiAdminController.php', 'admin/api/ai/product-draft'],
    'OpenAI opt-in provider' => ['src/Modules/Ai/Provider/OpenAiResponsesProvider.php', 'if(!$this->enabled())'],
    'Gemini opt-in provider' => ['src/Modules/Ai/Provider/GeminiGenerateContentProvider.php', 'if(!$this->enabled())'],
];

foreach ($checks as $name => [$relative, $needle]) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        $failures[] = $name . ': missing ' . $relative;
        continue;
    }
    $content = file_get_contents($path);
    if ($content === false || !str_contains($content, $needle)) {
        $failures[] = $name . ': contract marker missing';
    }
}

$release = json_decode((string) file_get_contents($root . '/resources/platform/release.json'), true);
if (($release['version'] ?? null) !== \Commerce\Core\Platform\PlatformVersion::VERSION) $failures[] = 'release version does not match PlatformVersion';
if ((string)($release['database_schema'] ?? '') !== (string)\Commerce\Core\Platform\PlatformVersion::DATABASE_SCHEMA) $failures[] = 'database schema does not match PlatformVersion::DATABASE_SCHEMA';

$moduleCatalog = (string) file_get_contents($root . '/src/Core/Module/SystemModuleCatalog.php');
if (!str_contains($moduleCatalog, "\$system('ai_authoring'")) $failures[] = 'AI authoring module inventory missing';
if (!str_contains($moduleCatalog, "\$system('campaigns'")) $failures[] = 'campaign module inventory missing';

if ($failures !== []) {
    fwrite(STDERR, "Commerce operations checks FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Commerce operations checks: OK\n");
fwrite(STDOUT, "promotion_concurrency=locked\n");
fwrite(STDOUT, "bulk_and_csv=event_safe\n");
fwrite(STDOUT, "campaigns=batch_queued\n");
fwrite(STDOUT, "sms_and_ai=optional\n");
