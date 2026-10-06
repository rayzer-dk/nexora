<?php

declare(strict_types=1);

// E2E helper. `create` adds a succeeded partial refund to a paid order that has no fiscal receipt yet and prints {"order":"<uuid>","refund":<id>};
// `delete <id>` removes that refund and the fiscal receipts of its order again.
$url = parse_url((string) getenv('DATABASE_URL'));
if (!is_array($url) || ($url['scheme'] ?? '') !== 'mysql') {
    fwrite(STDERR, "Invalid E2E database.\n");
    exit(2);
}
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', (string) ($url['host'] ?? '127.0.0.1'), (int) ($url['port'] ?? 3306), ltrim((string) ($url['path'] ?? ''), '/')),
    rawurldecode((string) ($url['user'] ?? '')),
    rawurldecode((string) ($url['pass'] ?? '')),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$mode = (string) ($argv[1] ?? '');
if ($mode === 'delete') {
    $id = (int) ($argv[2] ?? 0);
    $pdo->prepare("DELETE FROM mc_fiscal_receipt WHERE order_id=(SELECT p.order_id FROM mc_payment_refund r JOIN mc_payment p ON p.id=r.payment_id WHERE r.id=?)")->execute([$id]);
    $pdo->prepare('DELETE FROM mc_payment_refund WHERE id=?')->execute([$id]);
    echo "ok\n";
    exit(0);
}
$row = $pdo->query("SELECT p.id AS payment_id, p.amount_minor, o.public_id FROM mc_payment p JOIN mc_sales_order o ON o.id=p.order_id
    LEFT JOIN mc_fiscal_receipt f ON f.order_id=o.id AND f.kind='sale'
    WHERE p.status='paid' AND p.refunded_minor=0 AND p.amount_minor>=200 AND f.id IS NULL AND p.provider_code NOT IN ('bank_transfer','b2b_invoice')
      AND NOT EXISTS (SELECT 1 FROM mc_payment_refund r WHERE r.payment_id=p.id) ORDER BY p.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!is_array($row)) {
    fwrite(STDERR, "No suitable paid order.\n");
    exit(3);
}
$now = gmdate('Y-m-d H:i:s.u');
$pdo->prepare("INSERT INTO mc_payment_refund (public_id,payment_id,provider_reference,idempotency_key,amount_minor,status,provider_payload,created_at,updated_at) VALUES (?,?,?,?,?,'succeeded','{}',?,?)")
    ->execute([random_bytes(16), (int) $row['payment_id'], 'e2e-refund', 'e2e-' . bin2hex(random_bytes(8)), intdiv((int) $row['amount_minor'], 2), $now, $now]);
$refundId = (int) $pdo->lastInsertId();
$hex = bin2hex((string) $row['public_id']);
$uuid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
echo json_encode(['order' => $uuid, 'refund' => $refundId]) . "\n";
