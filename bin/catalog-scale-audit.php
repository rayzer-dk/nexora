#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = json_decode((string) file_get_contents($root . '/config/performance/concurrency.json'), true, 64, JSON_THROW_ON_ERROR);
$requiredFiles = [
    'src/Modules/Storefront/Infrastructure/CachedStorefrontCatalogQuery.php',
    'migrations/Version20260924130000.php',
    'migrations/Version20260924150000.php',
    'src/Modules/Search/Infrastructure/MeilisearchCandidateProvider.php',
    'src/Modules/Search/Infrastructure/MeilisearchProductIndexer.php',
    'src/Modules/Storefront/Projection/StorefrontProductProjectionService.php',
    'src/Modules/Storefront/Projection/StorefrontFacetProjectionStore.php',
    'qa/load/k6-storefront.js',
    'config/performance/budgets.json',
    'config/performance/concurrency.json',
];
$failed = false;
foreach ($requiredFiles as $relative) {
    $ok = is_file($root . '/' . $relative);
    printf("%-58s %s\n", $relative, $ok ? 'OK' : 'MISSING');
    $failed = $failed || !$ok;
}

$databaseUrl = getenv('DATABASE_URL') ?: '';
if ($databaseUrl === '') {
    echo "\nLive DB plan checks: SKIPPED (DATABASE_URL is not configured).\n";
    echo "Static target: 5k-50k products is supported by design. Current release keeps the external search/read projections and asset-performance hardening, and adds production-critical queue/install safeguards; concurrency capacity still requires live load testing.\n";
    exit($failed ? 1 : 0);
}

if (!str_starts_with($databaseUrl, 'mysql://') && !str_starts_with($databaseUrl, 'mariadb://')) {
    fwrite(STDERR, "Unsupported DATABASE_URL for scale audit.\n");
    exit(1);
}

echo "\nDATABASE_URL detected. Use bin/performance-audit.php plus EXPLAIN ANALYZE on representative catalog/search queries before release acceptance.\n";
exit($failed ? 1 : 0);
