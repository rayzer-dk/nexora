<?php

declare(strict_types=1);

namespace Commerce\Modules\Customer\Application;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class DbalCustomerAccountQuery
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function profile(int $customerId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT public_id,email,email_verified_at,phone_e164,display_name,locale,status,created_at,last_seen_at FROM mc_customer WHERE id=? LIMIT 1',
            [$customerId],
        );
        if (!is_array($row)) {
            return null;
        }
        $row['public_id'] = Uuid::fromBinary((string) $row['public_id'])->toRfc4122();
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function orders(int $customerId, int $storeId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->db->fetchAllAssociative(
            'SELECT public_id,order_number,status,payment_status,fulfillment_status,currency,total_minor,created_at FROM mc_sales_order WHERE customer_id=? AND store_id=? ORDER BY id DESC LIMIT '.$limit,
            [$customerId, $storeId],
        );
        foreach ($rows as &$row) {
            $row['public_id'] = Uuid::fromBinary((string) $row['public_id'])->toRfc4122();
            $row['total_minor'] = (int) $row['total_minor'];
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function order(int $customerId, int $storeId, string $publicId): ?array
    {
        try {
            $binary = Uuid::fromString($publicId)->toBinary();
        } catch (\Throwable) {
            return null;
        }
        $order = $this->db->fetchAssociative(
            'SELECT id,public_id,order_number,status,payment_status,fulfillment_status,currency,subtotal_minor,discount_minor,shipping_minor,tax_minor,total_minor,customer_email,customer_phone,customer_name,created_at FROM mc_sales_order WHERE public_id=? AND customer_id=? AND store_id=? LIMIT 1',
            [$binary, $customerId, $storeId],
        );
        if (!is_array($order)) {
            return null;
        }
        $order['public_id'] = Uuid::fromBinary((string) $order['public_id'])->toRfc4122();
        foreach (['subtotal_minor','discount_minor','shipping_minor','tax_minor','total_minor'] as $key) {
            $order[$key] = (int) $order[$key];
        }
        $order['items'] = $this->db->fetchAllAssociative(
            'SELECT id,product_id,variant_id,sku,name,quantity,unit_code,unit_price_minor,line_total_minor FROM mc_sales_order_item WHERE order_id=? ORDER BY id ASC',
            [(int) $order['id']],
        );
        unset($order['id']);
        return $order;
    }
}
