#!/usr/bin/env php
<?php

declare(strict_types=1);

$url = getenv('DATABASE_URL') ?: '';
$parts = parse_url($url);
if ($url === '' || !is_array($parts) || !isset($parts['host'], $parts['path'])) {
    fwrite(STDERR, "Forum schema runtime check requires DATABASE_URL.\n");
    exit(2);
}

$dbName = ltrim((string) $parts['path'], '/');
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $parts['host'], (int) ($parts['port'] ?? 3306), $dbName);
$pdo = new PDO($dsn, urldecode((string) ($parts['user'] ?? '')), urldecode((string) ($parts['pass'] ?? '')), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$requiredTables = [
    'mc_forum_board',
    'mc_forum_topic',
    'mc_forum_post',
    'mc_forum_subscription',
    'mc_forum_reaction',
    'mc_forum_report',
    'mc_forum_post_revision',
    'mc_forum_topic_read',
];

$requiredColumns = [
    'mc_forum_topic' => ['customer_id', 'views_count'],
    'mc_forum_post' => ['customer_id', 'edited_at', 'edit_count'],
    'mc_forum_subscription' => ['store_id', 'topic_id', 'customer_id', 'created_at'],
    'mc_forum_reaction' => ['post_id', 'customer_id', 'reaction', 'created_at'],
    'mc_forum_report' => ['store_id', 'post_id', 'customer_id', 'reason', 'status', 'resolved_at'],
    'mc_forum_post_revision' => ['post_id', 'editor_customer_id', 'body_text', 'created_at'],
    'mc_forum_topic_read' => ['topic_id', 'customer_id', 'last_read_post_id', 'read_at'],
];

$requiredForeignKeys = [
    'fk_forum_topic_customer',
    'fk_forum_post_customer',
    'fk_forum_subscription_store',
    'fk_forum_subscription_topic',
    'fk_forum_subscription_customer',
    'fk_forum_reaction_post',
    'fk_forum_reaction_customer',
    'fk_forum_report_store',
    'fk_forum_report_post',
    'fk_forum_report_customer',
    'fk_forum_post_revision_post',
    'fk_forum_post_revision_customer',
    'fk_forum_topic_read_topic',
    'fk_forum_topic_read_customer',
];

$errors = [];
$stmt = $pdo->prepare('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=?');
$stmt->execute([$dbName]);
$tables = array_fill_keys(array_column($stmt->fetchAll(), 'TABLE_NAME'), true);
foreach ($requiredTables as $table) {
    if (!isset($tables[$table])) {
        $errors[] = 'missing table ' . $table;
    }
}

$columnStmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
foreach ($requiredColumns as $table => $columns) {
    $columnStmt->execute([$dbName, $table]);
    $actual = array_fill_keys(array_column($columnStmt->fetchAll(), 'COLUMN_NAME'), true);
    foreach ($columns as $column) {
        if (!isset($actual[$column])) {
            $errors[] = 'missing column ' . $table . '.' . $column;
        }
    }
}

$fkStmt = $pdo->prepare("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=? AND CONSTRAINT_TYPE='FOREIGN KEY'");
$fkStmt->execute([$dbName]);
$foreignKeys = array_fill_keys(array_column($fkStmt->fetchAll(), 'CONSTRAINT_NAME'), true);
foreach ($requiredForeignKeys as $constraint) {
    if (!isset($foreignKeys[$constraint])) {
        $errors[] = 'missing foreign key ' . $constraint;
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Forum schema runtime check FAILED\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "Forum schema runtime check: PASSED\n";
echo "tables=" . count($requiredTables) . " foreign_keys=" . count($requiredForeignKeys) . " schema=46\n";
