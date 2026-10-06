<?php

declare(strict_types=1);

// Prints the numeric id of the customer with the e-mail given as the first argument.
$url = parse_url((string) getenv('DATABASE_URL'));
$email = mb_strtolower(trim((string) ($argv[1] ?? '')));
if (!is_array($url) || ($url['scheme'] ?? '') !== 'mysql' || $email === '') {
    fwrite(STDERR, "Invalid E2E lookup.\n");
    exit(2);
}
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', (string) ($url['host'] ?? '127.0.0.1'), (int) ($url['port'] ?? 3306), ltrim((string) ($url['path'] ?? ''), '/')),
    rawurldecode((string) ($url['user'] ?? '')),
    rawurldecode((string) ($url['pass'] ?? '')),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$stmt = $pdo->prepare('SELECT id FROM mc_customer WHERE email=? LIMIT 1');
$stmt->execute([$email]);
$id = $stmt->fetchColumn();
if ($id === false) {
    fwrite(STDERR, "Customer not found.\n");
    exit(3);
}
echo (int) $id;
