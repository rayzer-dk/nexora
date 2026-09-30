<?php

declare(strict_types=1);

$email = mb_strtolower(trim((string) ($argv[1] ?? '')));
$url = parse_url((string) getenv('DATABASE_URL'));
if ($email === '' || !is_array($url) || ($url['scheme'] ?? '') !== 'mysql') {
    fwrite(STDERR, "Invalid E2E reset lookup configuration.\n");
    exit(2);
}
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', (string) ($url['host'] ?? '127.0.0.1'), (int) ($url['port'] ?? 3306), ltrim((string) ($url['path'] ?? ''), '/')),
    rawurldecode((string) ($url['user'] ?? '')),
    rawurldecode((string) ($url['pass'] ?? '')),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$stmt = $pdo->prepare("SELECT payload FROM mc_notification_outbox WHERE recipient=? AND notification_type='admin_password_reset' ORDER BY id DESC LIMIT 1");
$stmt->execute([$email]);
$payload = $stmt->fetchColumn();
if (!is_string($payload) || $payload === '') {
    fwrite(STDERR, "Reset notification not found.\n");
    exit(3);
}
$data = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
echo (string) ($data['context']['action_url'] ?? '');
