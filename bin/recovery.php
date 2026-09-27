#!/usr/bin/env php
<?php

declare(strict_types=1);

$projectDir = dirname(__DIR__);
require $projectDir . '/src/Core/Recovery/NativeDatabaseConnectionFactory.php';
require $projectDir . '/src/Core/Recovery/RecoveryArchiveRestorer.php';

use Commerce\Core\Recovery\NativeDatabaseConnectionFactory;
use Commerce\Core\Recovery\RecoveryArchiveRestorer;

function usage(): void
{
    $text = <<<TXT
Nexora Commerce emergency recovery

Usage:
  php bin/recovery.php --list
  php bin/recovery.php --inspect SNAPSHOT_KEY
  php bin/recovery.php --restore SNAPSHOT_KEY --yes
  php bin/recovery.php --restore SNAPSHOT_KEY --yes --files-only
  php bin/recovery.php --restore SNAPSHOT_KEY --yes --database-only
  php bin/recovery.php --disable-maintenance

Recovery archives are read only from var/recovery/snapshots.
TXT;
    fwrite(STDOUT, $text . PHP_EOL);
}

function snapshotPath(string $projectDir, string $key): string
{
    if (preg_match('/^[A-Za-z0-9._-]{8,96}$/D', $key) !== 1) {
        throw new RuntimeException('Invalid snapshot key.');
    }
    return $projectDir . '/var/recovery/snapshots/' . $key . '.zip';
}

$args = $argv;
array_shift($args);
if ($args === [] || in_array('--help', $args, true) || in_array('-h', $args, true)) {
    usage();
    exit(0);
}

try {
    if (in_array('--list', $args, true)) {
        $directory = $projectDir . '/var/recovery/snapshots';
        if (!is_dir($directory)) {
            fwrite(STDOUT, "No recovery snapshots found.\n");
            exit(0);
        }
        $files = glob($directory . '/*.zip') ?: [];
        rsort($files, SORT_STRING);
        foreach ($files as $file) {
            fwrite(STDOUT, basename($file, '.zip') . "\t" . number_format((int) filesize($file)) . " bytes\n");
        }
        exit(0);
    }

    if (in_array('--disable-maintenance', $args, true)) {
        $flag = $projectDir . '/var/maintenance.flag';
        if (is_file($flag) && !@unlink($flag)) {
            throw new RuntimeException('Unable to remove maintenance flag.');
        }
        fwrite(STDOUT, "Maintenance mode disabled.\n");
        exit(0);
    }

    $inspectIndex = array_search('--inspect', $args, true);
    if ($inspectIndex !== false) {
        $key = (string) ($args[$inspectIndex + 1] ?? '');
        $path = snapshotPath($projectDir, $key);
        $info = (new RecoveryArchiveRestorer($projectDir))->inspect($path);
        $manifest = $info['manifest'];
        fwrite(STDOUT, 'Snapshot: ' . ($manifest['snapshot_key'] ?? $key) . PHP_EOL);
        fwrite(STDOUT, 'Platform: ' . ($manifest['platform_version'] ?? 'unknown') . PHP_EOL);
        fwrite(STDOUT, 'Created: ' . ($manifest['created_at'] ?? 'unknown') . PHP_EOL);
        fwrite(STDOUT, 'Reason: ' . ($manifest['reason'] ?? 'unknown') . PHP_EOL);
        fwrite(STDOUT, 'SHA-256: ' . ($info['sha256'] ?? 'unknown') . PHP_EOL);
        fwrite(STDOUT, 'Size: ' . number_format((int) ($info['size_bytes'] ?? 0)) . " bytes\n");
        exit(0);
    }

    $restoreIndex = array_search('--restore', $args, true);
    if ($restoreIndex !== false) {
        $key = (string) ($args[$restoreIndex + 1] ?? '');
        if (!in_array('--yes', $args, true)) {
            throw new RuntimeException('Restore is destructive. Re-run with --yes after checking the snapshot key.');
        }
        $filesOnly = in_array('--files-only', $args, true);
        $databaseOnly = in_array('--database-only', $args, true);
        if ($filesOnly && $databaseOnly) {
            throw new RuntimeException('Choose either --files-only or --database-only, not both.');
        }
        $restoreFiles = !$databaseOnly;
        $restoreDatabase = !$filesOnly;
        $path = snapshotPath($projectDir, $key);
        $pdo = $restoreDatabase ? NativeDatabaseConnectionFactory::fromProject($projectDir) : null;
        fwrite(STDOUT, "Recovery started. Storefront maintenance mode will stay enabled if recovery fails.\n");
        $result = (new RecoveryArchiveRestorer($projectDir))->restore($path, $pdo, $restoreFiles, $restoreDatabase);
        fwrite(STDOUT, 'Recovery completed from ' . $result['snapshot_key'] . ".\n");
        exit(0);
    }

    usage();
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, 'Recovery failed: ' . preg_replace('/[\r\n\t]+/', ' ', $e->getMessage()) . PHP_EOL);
    fwrite(STDERR, "Maintenance mode remains enabled when a restore fails.\n");
    exit(1);
}
