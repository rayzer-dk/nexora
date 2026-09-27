<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Core/Platform/PlatformVersion.php';

$root = dirname(__DIR__);
$failures = [];
$mustContain = static function (string $file, array $needles) use ($root, &$failures): void {
    $path = $root . '/' . $file;
    $data = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($data)) { $failures[] = 'missing ' . $file; return; }
    foreach ($needles as $needle) if (!str_contains($data, $needle)) $failures[] = $file . ' missing contract: ' . $needle;
};

$mustContain('src/Core/Recovery/RecoverySnapshotService.php', ["['recovery', 'full', 'data', 'database']", "public/media", "public function delete"]);
$mustContain('src/Modules/Admin/Http/SystemAdminController.php', ['admin_system_recovery_download', 'admin_system_recovery_restore_data', "pre-admin-restore"]);
$mustContain('src/Modules/Feeds/Application/ProductFeedGenerator.php', ["'google'", "'pinterest'", "'tiktok'", "'rozetka'", "'prom'", 'createElementNS', 'mc_feed_category_mapping']);
$mustContain('src/Modules/Feeds/Application/CanonicalProductExportService.php', ['variant_public_id', 'available_quantity', 'additional_image_link', 'google_product_category']);
$mustContain('src/Modules/Feeds/Application/FeedStorageService.php', ['.tmp-', 'rename($tmp,$path)', 'sha256']);
$mustContain('src/Modules/Feeds/Http/PublicFeedController.php', ['HTTP_SERVICE_UNAVAILABLE', 'Feed is not generated yet.']);
$mustContain('src/Modules/Seo/Http/SitemapController.php', ["/robots.txt", "/sitemap.xml", 'indexable=1', 'PAGE_SIZE=20000']);
$mustContain('migrations/Version20260924200000.php', ['mc_feed_category_mapping']);
$mustContain('public/setup.php', ["'dom'"]);

$release = json_decode((string) @file_get_contents($root . '/resources/platform/release.json'), true);
if (($release['version'] ?? null) !== \Commerce\Core\Platform\PlatformVersion::VERSION) $failures[] = 'release version does not match PlatformVersion';
if ((string)($release['database_schema'] ?? '') !== (string)\Commerce\Core\Platform\PlatformVersion::DATABASE_SCHEMA) $failures[] = 'database schema does not match PlatformVersion::DATABASE_SCHEMA';

if ($failures !== []) {
    fwrite(STDERR, "Backup/Feed/SEO check FAILED\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Backup/Feed/SEO check OK\n";
