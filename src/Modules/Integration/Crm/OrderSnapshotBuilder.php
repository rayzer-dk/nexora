<?php

declare(strict_types=1);

namespace Commerce\Modules\Integration\Crm;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** The facts of one order in a plain shape (money as decimals, goods as a list) that a connection template can use. */
final readonly class OrderSnapshotBuilder
{
    public function __construct(private Connection $db, private string $publicBaseUrl)
    {
    }

    /** @return array<string,mixed>|null null when the order does not exist */
    public function build(string $orderPublicId): ?array
    {
        try {
            $binary = Uuid::fromString($orderPublicId)->toBinary();
        } catch (\Throwable) {
            return null;
        }
        $o = $this->db->fetchAssociative('SELECT * FROM mc_sales_order WHERE public_id=? LIMIT 1', [$binary]);
        if (!is_array($o)) {
            return null;
        }
        $orderId = (int) $o['id'];
        $currency = (string) $o['currency'];
        $units = (int) ($this->db->fetchOne('SELECT minor_units FROM mc_currency WHERE code=?', [$currency]) ?: 2);
        $money = static fn (mixed $minor): string => number_format(((int) $minor) / (10 ** $units), $units, '.', '');
        $payment = $this->db->fetchAssociative('SELECT provider_code,status,amount_minor FROM mc_payment WHERE order_id=? ORDER BY id DESC LIMIT 1', [$orderId]) ?: [];
        $ful = $this->db->fetchAssociative('SELECT provider_code,service_type,status,tracking_number,destination_snapshot FROM mc_fulfillment WHERE order_id=? ORDER BY id DESC LIMIT 1', [$orderId]) ?: [];
        $dest = json_decode((string) ($ful['destination_snapshot'] ?? ''), true);
        $dest = is_array($dest) ? $dest : [];
        $items = [];
        foreach ($this->db->fetchAllAssociative('SELECT sku,name,quantity,unit_code,unit_price_minor,line_total_minor FROM mc_sales_order_item WHERE order_id=? ORDER BY id', [$orderId]) as $row) {
            $items[] = [
                'sku' => (string) $row['sku'], 'name' => (string) $row['name'], 'quantity' => $this->quantity((string) $row['quantity']), 'unit' => (string) $row['unit_code'],
                'price' => $money($row['unit_price_minor']), 'total' => $money($row['line_total_minor']),
            ];
        }
        $name = trim((string) $o['customer_name']);
        $parts = preg_split('/\s+/u', $name, 2) ?: [];
        $city = (string) ($dest['city'] ?? '');
        $point = (string) ($dest['point'] ?? '');
        $manual = (string) ($dest['manual'] ?? '');

        return [
            'order' => [
                'number' => (string) $o['order_number'], 'public_id' => $orderPublicId, 'status' => (string) $o['status'], 'payment_status' => (string) $o['payment_status'],
                'fulfillment_status' => (string) $o['fulfillment_status'], 'currency' => $currency, 'total' => $money($o['total_minor']), 'subtotal' => $money($o['subtotal_minor']),
                'discount' => $money($o['discount_minor']), 'shipping' => $money($o['shipping_minor']), 'tax' => $money($o['tax_minor']), 'comment' => (string) ($o['customer_comment'] ?? ''),
                'created_at' => substr((string) $o['created_at'], 0, 19), 'locale' => (string) $o['locale'],
                'admin_url' => rtrim($this->publicBaseUrl, '/') . '/admin/orders/' . $orderPublicId,
            ],
            'customer' => [
                'name' => $name, 'first_name' => (string) ($parts[0] ?? ''), 'last_name' => (string) ($parts[1] ?? ''), 'phone' => (string) ($o['customer_phone'] ?? ''),
                'email' => (string) ($o['customer_email'] ?? ''), 'company' => (string) ($o['company_name'] ?? ''), 'tax_id' => (string) ($o['company_tax_id'] ?? ''),
            ],
            'delivery' => [
                'provider' => (string) ($ful['provider_code'] ?? ''), 'service' => (string) ($ful['service_type'] ?? ''), 'status' => (string) ($ful['status'] ?? ''),
                'tracking' => (string) ($ful['tracking_number'] ?? ''), 'country' => (string) ($dest['country'] ?? ''), 'region' => (string) ($dest['region'] ?? ''), 'city' => $city,
                'point' => $point, 'manual' => $manual, 'address_line' => implode(', ', array_filter([$city, $point, $manual], static fn (string $v): bool => $v !== '')),
            ],
            'payment' => ['provider' => (string) ($payment['provider_code'] ?? ''), 'status' => (string) ($payment['status'] ?? ''), 'amount' => $money($payment['amount_minor'] ?? 0)],
            'items' => $items,
            'store' => ['url' => rtrim($this->publicBaseUrl, '/')],
        ];
    }

    private function quantity(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.') === '' ? '0' : rtrim(rtrim($value, '0'), '.');
    }
}
