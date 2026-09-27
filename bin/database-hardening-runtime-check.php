#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$url = getenv('DATABASE_URL') ?: '';
$parts = parse_url($url);
if ($url === '' || !is_array($parts) || !isset($parts['host'], $parts['path'])) {
    fwrite(STDERR, "Database hardening runtime check requires DATABASE_URL.\n");
    exit(2);
}

$dbName = ltrim((string) $parts['path'], '/');
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $parts['host'],
    (int) ($parts['port'] ?? 3306),
    $dbName,
);
$pdo = new PDO(
    $dsn,
    urldecode((string) ($parts['user'] ?? '')),
    urldecode((string) ($parts['pass'] ?? '')),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);

$errors = [];

$databaseCollation = strtolower((string) $pdo->query('SELECT @@collation_database')->fetchColumn());
if ($databaseCollation !== 'utf8mb4_unicode_ci') {
    $errors[] = 'database collation=' . $databaseCollation;
}

$stmt = $pdo->prepare(
    "SELECT TABLE_NAME,TABLE_COLLATION
     FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME LIKE 'mc\\_%'
     ORDER BY TABLE_NAME"
);
$stmt->execute([$dbName]);
$tables = $stmt->fetchAll();
foreach ($tables as $table) {
    $collation = strtolower((string) ($table['TABLE_COLLATION'] ?? ''));
    if ($collation !== 'utf8mb4_unicode_ci') {
        $errors[] = (string) $table['TABLE_NAME'] . ' collation=' . $collation;
    }
}

$ledger = (int) $pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mc_migration_versions'"
)->fetchColumn();
if ($ledger !== 1) {
    $errors[] = 'mc_migration_versions ledger missing';
}

$doctrine = (string) @file_get_contents($root . '/config/packages/doctrine.yaml');
if (!str_contains($doctrine, "SET time_zone = '+00:00'")) {
    $errors[] = 'Doctrine DB session UTC pin missing';
}

$runner = (string) @file_get_contents($root . '/src/Core/Update/CoreUpdateMigrationRunner.php');
if (!str_contains($runner, "LEDGER_TABLE = 'mc_migration_versions'")) {
    $errors[] = 'core update migration ledger mismatch';
}

if ($errors !== []) {
    fwrite(STDERR, "Database hardening runtime check FAILED\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "Database hardening runtime check: PASSED\n";
echo 'tables=' . count($tables) . " collation=utf8mb4_unicode_ci ledger=mc_migration_versions timezone=UTC\n";
