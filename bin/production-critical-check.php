#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

$config = '';
foreach (glob($root . '/config/*.{yaml,yml}', GLOB_BRACE) ?: [] as $file) {
    $config .= file_get_contents($file) ?: '';
}
foreach (glob($root . '/config/packages/*.{yaml,yml}', GLOB_BRACE) ?: [] as $file) {
    $config .= file_get_contents($file) ?: '';
}
preg_match_all('/%env\((?:[^:()]+:)?([A-Z0-9_]+)\)%/', $config, $envMatches);
$requiredEnv = array_values(array_unique($envMatches[1] ?? []));
sort($requiredEnv);

$setup = (string) file_get_contents($root . '/public/setup.php');
preg_match_all("/'([A-Z][A-Z0-9_]+)'\s*=>/", $setup, $setupMatches);
$setupEnv = array_values(array_unique($setupMatches[1] ?? []));
$missing = array_values(array_diff($requiredEnv, $setupEnv));
if ($missing !== []) {
    $errors[] = 'Browser installer misses runtime env keys: ' . implode(', ', $missing);
}

if (str_contains($setup, "'MESSENGER_TRANSPORT_DSN' => 'sync://'") || str_contains($setup, 'MESSENGER_TRANSPORT_DSN=sync://')) {
    $errors[] = 'Production installer must not force synchronous background transport.';
}

$googleSubscriber = (string) file_get_contents($root . '/src/Modules/GoogleCommerce/EventSubscriber/CatalogGoogleSyncSubscriber.php');
if (!str_contains($googleSubscriber, 'if (!$this->enabled)')) {
    $errors[] = 'Google Commerce subscriber lacks OFF fast-path.';
}
$marketingSubscriber = (string) file_get_contents($root . '/src/Modules/Marketing/EventSubscriber/CommerceMarketingSubscriber.php');
if (!str_contains($marketingSubscriber, '!$this->ga4Enabled') || !str_contains($marketingSubscriber, '!$this->metaEnabled') || !str_contains($marketingSubscriber, '!$this->tiktokEnabled')) {
    $errors[] = 'Marketing subscriber lacks all-providers-OFF fast-path.';
}

$worker = (string) file_get_contents($root . '/src/Modules/Integration/Application/IntegrationSyncWorker.php');
foreach (['recoverStaleJobs', 'locked_at', 'locked_by'] as $needle) {
    if (!str_contains($worker, $needle)) {
        $errors[] = 'Integration queue crash-recovery is incomplete: missing ' . $needle;
    }
}

$migration = (string) file_get_contents($root . '/migrations/Version20260924170000.php');
if (!str_contains($migration, 'idx_integration_sync_lease')) {
    $errors[] = 'Integration queue lease migration is missing.';
}

$purge = $root . '/src/Core/Health/Console/BackgroundQueuePurgeCommand.php';
$status = $root . '/src/Core/Health/Console/BackgroundQueueStatusCommand.php';
if (!is_file($purge) || !is_file($status)) {
    $errors[] = 'Background queue status/retention tooling is missing.';
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . PHP_EOL);
    }
    exit(1);
}

echo 'Production critical checks: OK' . PHP_EOL;
echo 'installer_env_keys=' . count($requiredEnv) . PHP_EOL;
echo 'disabled_integrations_fast_path=OK' . PHP_EOL;
echo 'integration_queue_crash_recovery=OK' . PHP_EOL;
echo 'queue_retention_tooling=OK' . PHP_EOL;
