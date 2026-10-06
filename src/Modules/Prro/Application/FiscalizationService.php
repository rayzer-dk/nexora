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
        $row = $this->db->fetchAssociative('SELECT * FROM mc_fiscal_receipt WHERE order_id=?', [$orderId]);

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
             LEFT JOIN mc_fiscal_receipt f ON f.order_id=o.id
             WHERE o.status NOT IN ('cancelled','expired') AND (f.id IS NULL OR (f.status='error' AND f.attempts<?))
               AND o.created_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
             ORDER BY o.id LIMIT " . max(1, min(100, $limit)),
            [self::MAX_ATTEMPTS],
        );
        $done = 0;
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
        $this->db->executeStatement("UPDATE mc_fiscal_receipt SET status='sending',attempts=attempts+1,updated_at=? WHERE order_id=?", [$now, $orderId]);

        try {
            $body = $this->buildReceipt($order, $uuid, $provider === 'cash_on_delivery' ? 'CASH' : 'CASHLESS', $cfg['send_email']);
            $token = $this->client->token($cfg);
            $this->client->ensureShift($cfg, $token);
            try {
                $created = $this->client->sell($cfg, $token, $body);
            } catch (CheckboxException $e) {
                if ($e->status !== 409 && !str_contains(mb_strtolower($e->getMessage()), 'already')) {
                    throw $e;
                }
                $created = $this->client->receipt($cfg, $token, $uuid); // the same receipt id was accepted before
            }
            $id = (string) ($created['id'] ?? $uuid);
            $fiscal = (string) ($created['fiscal_code'] ?? '');
            for ($i = 0; $fiscal === '' && $i < 3; ++$i) {
                usleep(400000);
                $fiscal = (string) ($this->client->receipt($cfg, $token, $id)['fiscal_code'] ?? '');
            }
            $this->db->update('mc_fiscal_receipt', [
                'status' => 'done', 'fiscal_code' => $fiscal !== '' ? mb_substr($fiscal, 0, 64) : null,
                'receipt_url' => $this->client->receiptUrl($cfg['environment'], $id), 'error_text' => null, 'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            ], ['order_id' => $orderId]);
        } catch (\Throwable $e) {
            $this->db->update('mc_fiscal_receipt', ['status' => 'error', 'error_text' => mb_substr($e->getMessage(), 0, 480), 'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')], ['order_id' => $orderId]);
            throw $e;
        }

        return $this->receiptOf($orderId) ?? [];
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
