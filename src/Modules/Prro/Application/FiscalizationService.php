<?php

declare(strict_types=1);

namespace Commerce\Modules\Prro\Application;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Issues the fiscal receipt (PRRO) of a paid order through Checkbox, once per order, and keeps the result next to the order. */
final class FiscalizationService
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly Connection $db, private readonly PrroSettings $settings, private readonly CheckboxClient $client)
    {
    }

    /** @return array<string,mixed>|null */
    public function receiptOf(int $orderId): ?array
    {
        $row = $this->db->fetchAssociative("SELECT * FROM mc_fiscal_receipt WHERE order_id=? AND kind='sale'", [$orderId]);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function returnReceiptOf(int $refundId): ?array
    {
        $row = $this->db->fetchAssociative("SELECT * FROM mc_fiscal_receipt WHERE kind='return' AND refund_id=?", [$refundId]);

        return is_array($row) ? $row : null;
    }

    /** Paid orders without a receipt, when the owner chose automatic receipts. Returns how many were handled. */
    public function processPending(int $limit = 20): int
    {
        $cfg = $this->settings->get();
        if (!$cfg['enabled'] || !$cfg['configured'] || $cfg['auto'] !== 'paid') {
            return 0;
        }
        $rows = $this->db->fetchFirstColumn(
            "SELECT o.id FROM mc_sales_order o
             JOIN mc_payment p ON p.order_id=o.id AND p.status IN ('paid','partially_refunded')
             LEFT JOIN mc_fiscal_receipt f ON f.order_id=o.id AND f.kind='sale'
             WHERE o.status NOT IN ('cancelled','expired') AND (f.id IS NULL OR (f.status='error' AND f.attempts<?))
               AND o.created_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
             ORDER BY o.id LIMIT " . max(1, min(100, $limit)),
            [self::MAX_ATTEMPTS],
        );
        $done = 0;
        foreach ($this->db->fetchFirstColumn(
            "SELECT r.id FROM mc_payment_refund r JOIN mc_payment p ON p.id=r.payment_id JOIN mc_fiscal_receipt s ON s.order_id=p.order_id AND s.kind='sale' AND s.status='done'
             LEFT JOIN mc_fiscal_receipt f ON f.kind='return' AND f.refund_id=r.id
             WHERE r.status='succeeded' AND (f.id IS NULL OR (f.status='error' AND f.attempts<?)) ORDER BY r.id LIMIT " . max(1, min(100, $limit)),
            [self::MAX_ATTEMPTS],
        ) as $refundId) {
            try {
                $this->fiscalizeRefund((int) $refundId);
                ++$done;
            } catch (\Throwable) {
                // Stored on the receipt row; the next run retries.
            }
        }
        foreach ($rows as $orderId) {
            try {
                $this->fiscalize((int) $orderId, false);
                ++$done;
            } catch (\Throwable) {
                // The reason is stored on the receipt row; the next run retries.
            }
        }

        return $done;
    }

    /** @return array<string,mixed> the receipt row */
    public function fiscalize(int $orderId, bool $manual): array
    {
        $cfg = $this->settings->get();
        if (!$cfg['enabled'] || !$cfg['configured']) {
            throw new \DomainException('prro_not_configured');
        }
        $order = $this->db->fetchAssociative('SELECT * FROM mc_sales_order WHERE id=?', [$orderId]);
        if (!is_array($order)) {
            throw new \DomainException('order_not_found');
        }
        $existing = $this->receiptOf($orderId);
        if ($existing !== null && $existing['status'] === 'done') {
            return $existing;
        }
        $payment = $this->db->fetchAssociative("SELECT provider_code,status FROM mc_payment WHERE order_id=? ORDER BY (status IN ('paid','partially_refunded')) DESC, id DESC LIMIT 1", [$orderId]) ?: [];
        $provider = (string) ($payment['provider_code'] ?? '');
        if (!$manual && in_array($provider, $cfg['skip'], true)) {
            throw new \DomainException('payment_method_skipped');
        }
        if (!in_array((string) ($payment['status'] ?? ''), ['paid', 'partially_refunded'], true)) {
            throw new \DomainException('order_not_paid');
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $uuid = $existing['receipt_uuid'] ?? Uuid::v4()->toRfc4122();
        if ($existing === null) {
            $this->db->insert('mc_fiscal_receipt', ['order_id' => $orderId, 'provider' => 'checkbox', 'receipt_uuid' => $uuid, 'status' => 'sending', 'total_minor' => (int) $order['total_minor'], 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->db->executeStatement("UPDATE mc_fiscal_receipt SET status='sending',attempts=attempts+1,updated_at=? WHERE order_id=? AND kind='sale'", [$now, $orderId]);

        try {
            $body = $this->buildReceipt($order, $uuid, $provider === 'cash_on_delivery' ? 'CASH' : 'CASHLESS', $cfg['send_email']);
            $this->submit($cfg, $uuid, $body, "kind='sale' AND order_id=" . $orderId);
        } catch (\Throwable $e) {
            $this->db->update('mc_fiscal_receipt', ['status' => 'error', 'error_text' => mb_substr($e->getMessage(), 0, 480), 'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')], ['order_id' => $orderId, 'kind' => 'sale']);
            throw $e;
        }

        return $this->receiptOf($orderId) ?? [];
    }

    /** @return array<string,mixed> the return receipt row */
    public function fiscalizeRefund(int $refundId): array
    {
        $cfg = $this->settings->get();
        if (!$cfg['enabled'] || !$cfg['configured']) {
            throw new \DomainException('prro_not_configured');
        }
        $existing = $this->returnReceiptOf($refundId);
        if ($existing !== null && $existing['status'] === 'done') {
            return $existing;
        }
        $refund = $this->db->fetchAssociative("SELECT r.id,r.amount_minor,r.status,p.order_id,p.amount_minor AS paid_minor,p.provider_code FROM mc_payment_refund r JOIN mc_payment p ON p.id=r.payment_id WHERE r.id=?", [$refundId]);
        if (!is_array($refund) || $refund['status'] !== 'succeeded') {
            throw new \DomainException('refund_not_ready');
        }
        $orderId = (int) $refund['order_id'];
        $sale = $this->receiptOf($orderId);
        if ($sale === null || $sale['status'] !== 'done') {
            throw new \DomainException('sale_receipt_missing');
        }
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $uuid = $existing['receipt_uuid'] ?? Uuid::v4()->toRfc4122();
        if ($existing === null) {
            $this->db->insert('mc_fiscal_receipt', ['order_id' => $orderId, 'kind' => 'return', 'refund_id' => $refundId, 'provider' => 'checkbox', 'receipt_uuid' => $uuid, 'status' => 'sending', 'total_minor' => (int) $refund['amount_minor'], 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->db->executeStatement("UPDATE mc_fiscal_receipt SET status='sending',attempts=attempts+1,updated_at=? WHERE kind='return' AND refund_id=?", [$now, $refundId]);
        try {
            $order = $this->db->fetchAssociative('SELECT * FROM mc_sales_order WHERE id=?', [$orderId]) ?: [];
            $type = (string) $refund['provider_code'] === 'cash_on_delivery' ? 'CASH' : 'CASHLESS';
            $amount = (int) $refund['amount_minor'];
            if ($amount >= (int) $refund['paid_minor']) {
                $body = $this->buildReceipt($order, $uuid, $type, false); // the whole order comes back: same lines, marked as returned
                foreach ($body['goods'] as &$good) {
                    $good['is_return'] = true;
                }
                unset($good);
            } else {
                $body = ['id' => $uuid, 'goods' => $this->partialRefundGoods($orderId, $amount), 'payments' => [['type' => $type, 'value' => $amount]]];
            }
            $body['related_receipt_id'] = (string) $sale['receipt_uuid'];
            $this->submit($cfg, $uuid, $body, "kind='return' AND refund_id=" . $refundId);
        } catch (\Throwable $e) {
            $this->db->executeStatement("UPDATE mc_fiscal_receipt SET status='error',error_text=?,updated_at=? WHERE kind='return' AND refund_id=?", [mb_substr($e->getMessage(), 0, 480), (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'), $refundId]);
            throw $e;
        }

        return $this->returnReceiptOf($refundId) ?? [];
    }

    /**
     * Goods of a partial return receipt. Real products are used when the refund can be matched to them
     * (a return request whose item refunds add up to the amount, or whole units of one order line);
     * otherwise the receipt keeps one "Partial refund" line for the amount.
     *
     * @return list<array<string,mixed>>
     */
    private function partialRefundGoods(int $orderId, int $amount): array
    {
        $fallback = [['good' => ['code' => 'REFUND', 'name' => 'Partial refund', 'price' => $amount], 'quantity' => 1000, 'is_return' => true]];
        $line = static function (string $code, string $name, int $sum, float $qty): array {
            $whole = $qty > 0 && abs($qty - round($qty)) < 0.0001 && $sum % (int) round($qty) === 0;
            $units = $whole ? (int) round($qty) : 1;
            $label = $whole ? $name : ($qty > 0 ? $name . ' x ' . rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.') : $name);

            return ['good' => ['code' => mb_substr($code, 0, 64), 'name' => mb_substr($label, 0, 250), 'price' => intdiv($sum, $units)], 'quantity' => $units * 1000, 'is_return' => true];
        };

        try {
            $returned = $this->db->fetchAllAssociative(
                "SELECT oi.sku,oi.name,SUM(ri.quantity) quantity,SUM(ri.refund_amount_minor) refund_minor
                 FROM mc_return_item ri
                 JOIN mc_return_request rr ON rr.id=ri.return_id AND rr.order_id=?
                 JOIN mc_sales_order_item oi ON oi.id=ri.order_item_id
                 WHERE ri.refund_amount_minor>0 AND rr.status NOT IN ('rejected','cancelled')
                 GROUP BY oi.id,oi.sku,oi.name ORDER BY oi.id",
                [$orderId],
            );
            if ($returned !== [] && array_sum(array_map(static fn (array $r): int => (int) $r['refund_minor'], $returned)) === $amount) {
                return array_map(static fn (array $r): array => $line((string) $r['sku'], (string) $r['name'], (int) $r['refund_minor'], (float) $r['quantity']), $returned);
            }
        } catch (\Throwable) {
            // Return tables are optional: fall through to the order lines.
        }

        foreach ($this->db->fetchAllAssociative('SELECT sku,name,quantity,unit_price_minor FROM mc_sales_order_item WHERE order_id=? ORDER BY id', [$orderId]) as $item) {
            $price = (int) $item['unit_price_minor'];
            if ($price > 0 && $amount % $price === 0 && $amount / $price <= (float) $item['quantity']) {
                return [$line((string) $item['sku'], (string) $item['name'], $amount, (float) ($amount / $price))];
            }
        }

        return $fallback;
    }

    /**
     * Sends a receipt, waits briefly for its fiscal number and stores the result on the row picked by $where (built from integers only).
     *
     * @param array<string,mixed> $cfg
     * @param array<string,mixed> $body
     */
    private function submit(array $cfg, string $uuid, array $body, string $where): void
    {
        $token = $this->client->token($cfg);
        $this->client->ensureShift($cfg, $token);
        try {
            $created = $this->client->sell($cfg, $token, $body);
        } catch (CheckboxException $e) {
            if ($e->status !== 409 && !str_contains(mb_strtolower($e->getMessage()), 'already')) {
                throw $e;
            }
            $created = $this->client->receipt($cfg, $token, $uuid);
        }
        $id = (string) ($created['id'] ?? $uuid);
        $fiscal = (string) ($created['fiscal_code'] ?? '');
        for ($i = 0; $fiscal === '' && $i < 3; ++$i) {
            usleep(400000);
            $fiscal = (string) ($this->client->receipt($cfg, $token, $id)['fiscal_code'] ?? '');
        }
        $this->db->executeStatement(
            "UPDATE mc_fiscal_receipt SET status='done',fiscal_code=?,receipt_url=?,error_text=NULL,updated_at=? WHERE " . $where,
            [$fiscal !== '' ? mb_substr($fiscal, 0, 64) : null, $this->client->receiptUrl($cfg['environment'], $id), (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')],
        );
    }

    /** Signs in and reads the shift: used by the "check connection" button. */
    public function testConnection(): string
    {
        $cfg = $this->settings->get();
        if (!$cfg['configured']) {
            throw new \DomainException('prro_not_configured');
        }
        $token = $this->client->token($cfg);
        $this->client->ensureShift($cfg, $token);

        return 'ok';
    }

    /**
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function buildReceipt(array $order, string $uuid, string $paymentType, bool $sendEmail): array
    {
        $items = $this->db->fetchAllAssociative('SELECT sku,name,quantity,unit_price_minor FROM mc_sales_order_item WHERE order_id=? ORDER BY id', [(int) $order['id']]);
        $lines = [];
        $sum = 0;
        foreach ($items as $item) {
            $qty = max(1, (int) round(((float) $item['quantity']) * 1000));
            $price = (int) $item['unit_price_minor'];
            $line = intdiv($price * $qty + 500, 1000);
            $lines[] = ['code' => mb_substr((string) $item['sku'], 0, 64), 'name' => mb_substr((string) $item['name'], 0, 250), 'price' => $price, 'quantity' => $qty, 'sum' => $line];
            $sum += $line;
        }
        $shipping = (int) $order['shipping_minor'];
        if ($shipping > 0) {
            $lines[] = ['code' => 'DELIVERY', 'name' => 'Delivery', 'price' => $shipping, 'quantity' => 1000, 'sum' => $shipping];
            $sum += $shipping;
        }
        $total = (int) $order['total_minor'];
        $discount = $sum - $total;
        if ($lines === [] || $total <= 0 || $discount < 0) {
            throw new \DomainException('totals_mismatch');
        }
        // One order-level discount (promotions, gift card, bonus points) is spread over the lines in proportion, the remainder lands on the last line.
        $left = $discount;
        $goods = [];
        foreach ($lines as $i => $line) {
            $share = $i === count($lines) - 1 ? $left : (int) floor($discount * $line['sum'] / $sum);
            $share = min($share, $line['sum']);
            $left -= $share;
            $good = ['good' => ['code' => $line['code'], 'name' => $line['name'], 'price' => $line['price']], 'quantity' => $line['quantity']];
            if ($share > 0) {
                $good['discounts'] = [['type' => 'DISCOUNT', 'mode' => 'VALUE', 'value' => $share]];
            }
            $goods[] = $good;
        }
        $receipt = ['id' => $uuid, 'goods' => $goods, 'payments' => [['type' => $paymentType, 'value' => $total]]];
        $email = trim((string) ($order['customer_email'] ?? ''));
        if ($sendEmail && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $receipt['delivery'] = ['email' => $email];
        }

        return $receipt;
    }
}
