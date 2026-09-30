<?php

declare(strict_types=1);

$email = mb_strtolower(trim((string) ($argv[1] ?? '')));
$type = ($argv[2] ?? 'customer_verification_code') === 'customer_device_code' ? 'customer_device_code' : 'customer_verification_code';
$url = parse_url((string) getenv('DATABASE_URL'));
if ($email === '' || !is_array($url) || ($url['scheme'] ?? '') !== 'mysql') {
    fwrite(STDERR, "Invalid E2E verification lookup configuration.\n");
    exit(2);
}
$dbName = ltrim((string) ($url['path'] ?? ''), '/');
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', (string) ($url['host'] ?? '127.0.0.1'), (int) ($url['port'] ?? 3306), $dbName),
    rawurldecode((string) ($url['user'] ?? '')),
    rawurldecode((string) ($url['pass'] ?? '')),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$stmt = $pdo->prepare("SELECT payload FROM mc_notification_outbox WHERE recipient=? AND notification_type=? ORDER BY id DESC LIMIT 1");
$stmt->execute([$email, $type]);
$payload = $stmt->fetchColumn();
if (!is_string($payload) || $payload === '') {
    fwrite(STDERR, "Verification notification not found.\n");
    exit(3);
}
$data = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
$code = (string) ($data['context']['verification_code'] ?? '');
if (preg_match('/^[0-9]{6}$/D', $code) !== 1) {
    fwrite(STDERR, "Verification code is missing from E2E outbox.\n");
    exit(4);
}
echo $code;
