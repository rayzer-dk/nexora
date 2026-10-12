<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketplace\Application;

use Commerce\Modules\Marketplace\Contract\MarketplaceAdapterInterface;
use Commerce\Modules\Marketplace\Domain\ExternalOrder;
use Commerce\Modules\Order\Application\ManualOrderService;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/**
 * One pass over every marketplace that is switched on: new orders come in (once each), changed stock and price go out,
 * order statuses go back. A failure of one marketplace or one order is logged and never stops the others.
 */
final class MarketplaceSyncService
{
    /** @param iterable<MarketplaceAdapterInterface> $adapters */
    public function __construct(
        private readonly Connection $db,
        private readonly ManualOrderService $orders,
        private readonly \Commerce\Modules\Order\Application\OrderManagementService $management,
        #[AutowireIterator('commerce.marketplace_adapter')] private readonly iterable $adapters,
    ) {
    }

    /** @return list<MarketplaceAdapterInterface> */
    public function adapters(bool $readyOnly = false): array
    {
        $out = [];
        foreach ($this->adapters as $adapter) {
            if (!$readyOnly || $adapter->isReady()) {
                $out[] = $adapter;
            }
        }

        return $out;
    }

    /** @return array<string,array{orders:int,stock:int,statuses:int,errors:int}> */
    public function run(?string $only = null): array
    {
        $result = [];
        foreach ($this->adapters(true) as $adapter) {
            if ($only !== null && $adapter->code() !== $only) {
                continue;
            }
            $stats = ['orders' => 0, 'stock' => 0, 'statuses' => 0, 'errors' => 0];
            $lastError = null;
            foreach (['orders' => fn (): int => $this->importOrders($adapter), 'stock' => fn (): int => $this->pushStock($adapter), 'statuses' => fn (): int => $this->pushStatuses($adapter)] as $step => $do) {
                if (!$this->enabled($adapter, $step)) {
                    continue;
                }
                try {
                    $stats[$step] = $do();
                } catch (\Throwable $e) {
                    ++$stats['errors'];
                    $lastError = $step . ': ' . mb_substr($e->getMessage(), 0, 400);
                    $this->log($adapter->code(), 'error', $lastError);
                }
            }
            $now = gmdate('Y-m-d H:i:s.u');
            $this->db->executeStatement(
                'INSERT INTO mc_marketplace_state (channel,last_run_at,imported_total,last_error) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE last_run_at=VALUES(last_run_at),imported_total=imported_total+VALUES(imported_total),last_error=VALUES(last_error)',
                [$adapter->code(), $now, $stats['orders'], $lastError],
            );
            $result[$adapter->code()] = $stats;
        }

        return $result;
    }

    /** The switches "import orders / push stock / push statuses" of a marketplace (all on by default). */
    private function enabled(MarketplaceAdapterInterface $adapter, string $step): bool
    {
        $name = strtoupper($adapter->code()) . '_' . ['orders' => 'IMPORT_ORDERS', 'stock' => 'PUSH_STOCK', 'statuses' => 'PUSH_STATUS'][$step];
        $value = \Commerce\Modules\Integration\Application\IntegrationSecretStore::effective($name);

        return $value !== '0';
    }

    private function importOrders(MarketplaceAdapterInterface $adapter): int
    {
        $since = $this->db->fetchOne('SELECT last_orders_at FROM mc_marketplace_state WHERE channel=?', [$adapter->code()]);
        $from = is_string($since) && $since !== '' ? new \DateTimeImmutable($since . ' UTC') : new \DateTimeImmutable('-3 days', new \DateTimeZone('UTC'));
        $from = $from->modify('-1 day');
        $started = gmdate('Y-m-d H:i:s.u');
        $store = $this->db->fetchAssociative("SELECT s.id AS store_id,m.id AS market_id,m.default_locale,m.default_currency FROM mc_market m JOIN mc_store s ON s.id=m.store_id WHERE m.status='active' ORDER BY s.id,m.id LIMIT 1");
        if (!is_array($store)) {
            throw new \RuntimeException('No active market to put the orders into.');
        }
        $imported = 0;
        foreach ($adapter->newOrders($from->format('Y-m-d\TH:i:s')) as $order) {
            if ($this->db->fetchOne('SELECT id FROM mc_marketplace_order WHERE channel=? AND external_id=?', [$order->channel, $order->externalId]) !== false) {
                continue;
            }
            try {
                $created = $this->orders->create((int) $store['store_id'], (int) $store['market_id'], (string) $store['default_locale'], (string) $store['default_currency'], 'marketplace:' . $order->channel, $this->input($order));
                $orderId = (int) $this->db->fetchOne('SELECT id FROM mc_sales_order WHERE public_id=?', [Uuid::fromString($created['public_id'])->toBinary()]);
                $now = gmdate('Y-m-d H:i:s.u');
                $this->db->insert('mc_marketplace_order', ['channel' => $order->channel, 'external_id' => $order->externalId, 'order_id' => $orderId, 'created_at' => $now, 'updated_at' => $now]);
                try {
                    $this->management->addInternalNote($created['public_id'], strtoupper($order->channel) . ' #' . $order->externalId . ': ' . $adapter->label(), 'marketplace:' . $order->channel);
                } catch (\Throwable) {
                    // The note is a convenience; the order is already taken.
                }
                ++$imported;
                $this->log($order->channel, 'info', 'Order ' . $order->externalId . ' became ' . $created['order_number']);
            } catch (\Throwable $e) {
                $this->log($order->channel, 'error', 'Order ' . $order->externalId . ' not imported: ' . mb_substr($e->getMessage(), 0, 300));
            }
        }
        $this->db->executeStatement('INSERT INTO mc_marketplace_state (channel,last_orders_at) VALUES (?,?) ON DUPLICATE KEY UPDATE last_orders_at=VALUES(last_orders_at)', [$adapter->code(), $started]);

        return $imported;
    }

    /** @return array{name:string,phone:string,email:string,comment:string,payment_method:string,delivery:string,coupon:string,lines:list<array{sku:string,quantity:string,price:string}>} */
    private function input(ExternalOrder $order): array
    {
        $lines = [];
        foreach ($order->lines as $line) {
            $sku = $this->skuFor($line['external_id'], $line['sku']);
            if ($sku === null) {
                throw new \DomainException('Unknown product ' . ($line['sku'] ?: $line['external_id']) . ' (match it by the SKU of the shop)');
            }
            $lines[] = ['sku' => $sku, 'quantity' => $line['quantity'], 'price' => $line['price']];
        }

        return [
            'name' => $order->name, 'phone' => $order->phone, 'email' => $order->email, 'coupon' => '', 'payment_method' => 'cash_on_delivery',
            'comment' => trim('[' . strtoupper($order->channel) . ' #' . $order->externalId . '] ' . $order->comment), 'delivery' => $order->delivery, 'lines' => $lines,
        ];
    }

    /** The SKU of the shop for a marketplace line: by the offer id of our feed (the variant UUID) first, then by the SKU. */
    private function skuFor(string $externalId, string $sku): ?string
    {
        if ($externalId !== '' && Uuid::isValid($externalId)) {
            $found = $this->db->fetchOne('SELECT sku FROM mc_product_variant WHERE public_id=?', [Uuid::fromString($externalId)->toBinary()]);
            if (is_string($found) && $found !== '') {
                return $found;
            }
        }
        if ($sku !== '' && $this->db->fetchOne('SELECT 1 FROM mc_product_variant WHERE sku=? LIMIT 1', [$sku]) !== false) {
            return $sku;
        }

        return null;
    }

    private function pushStock(MarketplaceAdapterInterface $adapter): int
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT v.id,v.public_id,v.sku,
                (SELECT GREATEST(0,FLOOR(sl.stocked_quantity-sl.reserved_quantity)) FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_market_inventory_location mil ON mil.location_id=sl.location_id AND mil.market_id=m.id WHERE vii.variant_id=v.id ORDER BY mil.priority,sl.location_id LIMIT 1) AS qty,
                (SELECT pr.amount_minor FROM mc_price pr WHERE pr.variant_id=v.id AND pr.store_id=m.store_id AND pr.market_id=m.id AND pr.customer_group='default' AND pr.price_list_id IS NULL AND pr.max_quantity IS NULL AND pr.starts_at IS NULL AND pr.ends_at IS NULL ORDER BY pr.priority,pr.min_quantity,pr.id DESC LIMIT 1) AS price_minor,
                ms.pushed_qty,ms.pushed_price_minor
             FROM mc_market m JOIN mc_store_product sp ON sp.store_id=m.store_id JOIN mc_product_variant v ON v.product_id=sp.product_id
             LEFT JOIN mc_marketplace_stock ms ON ms.channel=? AND ms.variant_id=v.id
             WHERE m.id=(SELECT id FROM mc_market WHERE status='active' ORDER BY id LIMIT 1)",
            [$adapter->code()],
        );
        $items = [];
        $sent = [];
        foreach ($rows as $r) {
            $qty = (int) ($r['qty'] ?? 0);
            $price = $r['price_minor'] !== null ? (int) $r['price_minor'] : null;
            if ($r['pushed_qty'] !== null && (int) $r['pushed_qty'] === $qty && ($r['pushed_price_minor'] === null ? null : (int) $r['pushed_price_minor']) === $price) {
                continue;
            }
            $items[] = ['external_id' => Uuid::fromBinary((string) $r['public_id'])->toRfc4122(), 'sku' => (string) $r['sku'], 'quantity' => $qty, 'price' => $price !== null ? number_format($price / 100, 2, '.', '') : null];
            $sent[] = [(int) $r['id'], $qty, $price];
        }
        if ($items === []) {
            return 0;
        }
        $adapter->pushStock($items);
        $now = gmdate('Y-m-d H:i:s.u');
        foreach ($sent as [$variantId, $qty, $price]) {
            $this->db->executeStatement(
                'INSERT INTO mc_marketplace_stock (channel,variant_id,pushed_qty,pushed_price_minor,pushed_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE pushed_qty=VALUES(pushed_qty),pushed_price_minor=VALUES(pushed_price_minor),pushed_at=VALUES(pushed_at)',
                [$adapter->code(), $variantId, $qty, $price, $now],
            );
        }

        return count($items);
    }

    private function pushStatuses(MarketplaceAdapterInterface $adapter): int
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT mo.id,mo.external_id,mo.pushed_status,o.status,o.fulfillment_status FROM mc_marketplace_order mo JOIN mc_sales_order o ON o.id=mo.order_id
             WHERE mo.channel=? AND (mo.pushed_status IS NULL OR mo.pushed_status NOT IN ('delivered','canceled')) ORDER BY mo.id DESC LIMIT 200",
            [$adapter->code()],
        );
        $count = 0;
        foreach ($rows as $r) {
            $status = self::neutralStatus((string) $r['status'], (string) $r['fulfillment_status']);
            if ($status === null || $status === $r['pushed_status']) {
                continue;
            }
            try {
                $adapter->pushStatus((string) $r['external_id'], $status);
                $this->db->update('mc_marketplace_order', ['pushed_status' => $status, 'updated_at' => gmdate('Y-m-d H:i:s.u')], ['id' => (int) $r['id']]);
                ++$count;
            } catch (\Throwable $e) {
                $this->log($adapter->code(), 'error', 'Status of ' . $r['external_id'] . ' not sent: ' . mb_substr($e->getMessage(), 0, 300));
            }
        }

        return $count;
    }

    /** The status the shop's order has, as a marketplace understands it; null when there is nothing to tell yet. */
    public static function neutralStatus(string $status, string $fulfillment): ?string
    {
        return match (true) {
            $status === 'cancelled' || $fulfillment === 'cancelled' => 'canceled',
            $status === 'completed' || $fulfillment === 'delivered' => 'delivered',
            $fulfillment === 'shipped' => 'shipped',
            in_array($status, ['confirmed', 'processing'], true) => 'confirmed',
            default => null,
        };
    }

    private function log(string $channel, string $level, string $message): void
    {
        $this->db->insert('mc_marketplace_log', ['channel' => $channel, 'level' => $level, 'message' => mb_substr($message, 0, 500), 'created_at' => gmdate('Y-m-d H:i:s.u')]);
        $this->db->executeStatement('DELETE FROM mc_marketplace_log WHERE channel=? AND id<(SELECT m FROM (SELECT COALESCE(MAX(id),0)-500 AS m FROM mc_marketplace_log WHERE channel=?) t)', [$channel, $channel]);
    }
}
