<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Commerce\Core\Event\DomainEventFactory;
use Commerce\Core\Event\EventBusInterface;
use Commerce\Core\Event\EventNames;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Stock changes from the product list (quick edit of one product, "zero stock" for a selection) without re-saving
 * the whole product. It writes the same point ProductWriter writes: the stock row of the market's first active
 * warehouse (by priority). Every change returns the previous quantities by mc_stock_level.id, so it can be undone.
 */
final readonly class ProductStockService
{
    public const MAX_PRODUCTS = 500;
    private const QUANTITY = '/^\d{1,9}(?:\.\d{1,6})?$/D';

    public function __construct(private Connection $db, private EventBusInterface $events, private DomainEventFactory $eventFactory)
    {
    }

    /**
     * Sets the stock of a product with one variant. Errors: format, not_found, variants, no_location, no_inventory.
     *
     * @return array{ok:bool,previous:array<int,string>,quantity:string,error?:string}
     */
    public function setStock(int $storeId, int $marketId, string $productPublicId, string $quantity): array
    {
        $raw = str_replace(',', '.', trim($quantity));
        if (preg_match(self::QUANTITY, $raw) !== 1) {
            return ['ok' => false, 'previous' => [], 'quantity' => '', 'error' => 'format'];
        }
        $value = self::normalize($raw);
        $product = $this->product($storeId, $productPublicId);
        if ($product === null) {
            return ['ok' => false, 'previous' => [], 'quantity' => '', 'error' => 'not_found'];
        }
        $variants = array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM mc_product_variant WHERE product_id=? ORDER BY sort_order,id', [$product['id']]));
        if (count($variants) !== 1) {
            return ['ok' => false, 'previous' => [], 'quantity' => '', 'error' => 'variants'];
        }
        $locationId = MarketStockLocation::locationId($this->db, $marketId);
        if ($locationId === 0) {
            return ['ok' => false, 'previous' => [], 'quantity' => '', 'error' => 'no_location'];
        }
        $itemId = $this->db->fetchOne('SELECT inventory_item_id FROM mc_variant_inventory_item WHERE variant_id=? ORDER BY inventory_item_id LIMIT 1', [$variants[0]]);
        if ($itemId === false) {
            return ['ok' => false, 'previous' => [], 'quantity' => '', 'error' => 'no_inventory'];
        }

        $previous = $this->db->transactional(function (Connection $db) use ($itemId, $locationId, $value, $product, $storeId, $marketId): array {
            $now = gmdate('Y-m-d H:i:s.u');
            $row = $db->fetchAssociative('SELECT id,stocked_quantity FROM mc_stock_level WHERE inventory_item_id=? AND location_id=?', [(int) $itemId, $locationId]);
            if (is_array($row)) {
                $db->executeStatement('UPDATE mc_stock_level SET stocked_quantity=?, row_version=row_version+1, updated_at=? WHERE id=?', [$value, $now, (int) $row['id']]);
                $previous = [(int) $row['id'] => self::normalize((string) $row['stocked_quantity'])];
            } else {
                $db->insert('mc_stock_level', [
                    'inventory_item_id' => (int) $itemId, 'location_id' => $locationId, 'stocked_quantity' => $value,
                    'reserved_quantity' => '0.000000', 'incoming_quantity' => '0.000000', 'safety_stock' => '0.000000', 'row_version' => 1, 'updated_at' => $now,
                ]);
                $previous = [(int) $db->lastInsertId() => '0.000000'];
            }
            $this->announce([$product], $storeId, $marketId);

            return $previous;
        });

        return ['ok' => true, 'previous' => $previous, 'quantity' => self::display($value)];
    }

    /**
     * Sets the stock of every active variant of the products to 0 at the market's warehouse. A product without an
     * inventory item (digital, service) is skipped; so is a product of another store.
     *
     * @param list<string> $productPublicIds
     * @return array{updated:int,skipped:int,previous:array<int,string>,error?:string}
     */
    public function zeroStock(int $storeId, int $marketId, array $productPublicIds): array
    {
        $binary = [];
        foreach (array_slice(array_values(array_unique(array_map(static fn ($id): string => strtolower(trim((string) $id)), $productPublicIds))), 0, self::MAX_PRODUCTS) as $publicId) {
            if (Uuid::isValid($publicId)) {
                $binary[] = Uuid::fromString($publicId)->toBinary();
            }
        }
        $requested = count($binary);
        $locationId = MarketStockLocation::locationId($this->db, $marketId);
        if ($locationId === 0) {
            // not "without stock tracking": the market has no active warehouse to write the stock to
            return ['updated' => 0, 'skipped' => $requested, 'previous' => [], 'error' => 'no_location'];
        }
        if ($requested === 0) {
            return ['updated' => 0, 'skipped' => 0, 'previous' => []];
        }
        $rows = $this->db->fetchAllAssociative(
            "SELECT p.id product_id,p.public_id,p.status,vii.inventory_item_id,sl.id stock_id,sl.stocked_quantity
             FROM mc_product p
             JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=?
             LEFT JOIN mc_product_variant v ON v.product_id=p.id AND v.status='active'
             LEFT JOIN mc_variant_inventory_item vii ON vii.variant_id=v.id
             LEFT JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id AND sl.location_id=?
             WHERE p.public_id IN (?)",
            [$storeId, $locationId, $binary],
            [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER, ArrayParameterType::STRING],
        );
        $products = [];
        $stocked = [];
        $previous = [];
        foreach ($rows as $row) {
            if ($row['inventory_item_id'] === null) {
                continue;
            }
            $products[(int) $row['product_id']] = ['id' => (int) $row['product_id'], 'public_id' => (string) $row['public_id'], 'status' => (string) $row['status']];
            if ($row['stock_id'] !== null) {
                $old = self::normalize((string) $row['stocked_quantity']);
                if ($old !== '0.000000') {
                    $stocked[] = (int) $row['stock_id'];
                    $previous[(int) $row['stock_id']] = $old;
                }
            }
        }
        if ($products !== []) {
            $this->db->transactional(function (Connection $db) use ($stocked, $products, $storeId, $marketId): void {
                if ($stocked !== []) {
                    $db->executeStatement(
                        'UPDATE mc_stock_level SET stocked_quantity=?, row_version=row_version+1, updated_at=? WHERE id IN (?)',
                        ['0.000000', gmdate('Y-m-d H:i:s.u'), array_values(array_unique($stocked))],
                        [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, ArrayParameterType::INTEGER],
                    );
                }
                $this->announce(array_values($products), $storeId, $marketId);
            });
        }

        return ['updated' => count($products), 'skipped' => $requested - count($products), 'previous' => $previous];
    }

    /**
     * Puts back quantities returned by setStock/zeroStock (the undo). Rows deleted in the meantime are ignored.
     *
     * @param array<int|string,string> $previous stock_level.id => quantity
     */
    public function restore(array $previous, ?int $storeId = null, ?int $marketId = null): int
    {
        $clean = [];
        foreach ($previous as $id => $quantity) {
            $quantity = str_replace(',', '.', trim((string) $quantity));
            if ((int) $id > 0 && preg_match(self::QUANTITY, $quantity) === 1) {
                $clean[(int) $id] = self::normalize($quantity);
            }
        }
        if ($clean === []) {
            return 0;
        }

        return $this->db->transactional(function (Connection $db) use ($clean, $storeId, $marketId): int {
            $now = gmdate('Y-m-d H:i:s.u');
            $restored = 0;
            foreach ($clean as $id => $quantity) {
                $restored += (int) $db->executeStatement('UPDATE mc_stock_level SET stocked_quantity=?, row_version=row_version+1, updated_at=? WHERE id=?', [$quantity, $now, $id]);
            }
            $products = $db->fetchAllAssociative(
                'SELECT DISTINCT p.id,p.public_id,p.status FROM mc_stock_level sl JOIN mc_variant_inventory_item vii ON vii.inventory_item_id=sl.inventory_item_id JOIN mc_product_variant v ON v.id=vii.variant_id JOIN mc_product p ON p.id=v.product_id WHERE sl.id IN (?)',
                [array_keys($clean)],
                [ArrayParameterType::INTEGER],
            );
            $this->announce($products, $storeId, $marketId);

            return $restored;
        });
    }

    /** A quantity for people: "12.500000" → "12.5", "3.000000" → "3". */
    public static function display(?string $quantity): string
    {
        if ($quantity === null || trim($quantity) === '') {
            return '';
        }
        $value = self::normalize($quantity);
        $value = rtrim(rtrim($value, '0'), '.');

        return $value === '' ? '0' : $value;
    }

    /** Six decimals, as stored in DECIMAL(18,6): "7.5" → "7.500000". */
    private static function normalize(string $quantity): string
    {
        $quantity = trim($quantity);
        $negative = str_starts_with($quantity, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($quantity, '-'), 2), 2, '');
        $whole = ltrim($whole, '0');

        return ($negative ? '-' : '') . ($whole === '' ? '0' : $whole) . '.' . str_pad(substr($fraction, 0, 6), 6, '0');
    }

    /** @return array{id:int,public_id:string,status:string}|null */
    private function product(int $storeId, string $publicId): ?array
    {
        if (!Uuid::isValid($publicId)) {
            return null;
        }
        $row = $this->db->fetchAssociative('SELECT p.id,p.public_id,p.status FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? WHERE p.public_id=? LIMIT 1', [$storeId, Uuid::fromString($publicId)->toBinary()]);

        return is_array($row) ? ['id' => (int) $row['id'], 'public_id' => (string) $row['public_id'], 'status' => (string) $row['status']] : null;
    }

    /**
     * Search, feeds and "back in stock" listen to the product-updated event.
     *
     * @param list<array<string,mixed>> $products
     */
    private function announce(array $products, ?int $storeId, ?int $marketId): void
    {
        foreach ($products as $product) {
            $payload = ['status' => (string) ($product['status'] ?? '')];
            if ($storeId !== null) {
                $payload = ['store_id' => $storeId, 'market_id' => $marketId] + $payload;
            }
            $this->events->publish($this->eventFactory->create(
                EventNames::PRODUCT_UPDATED,
                'product',
                Uuid::fromBinary((string) $product['public_id'])->toRfc4122(),
                $payload,
                ['source' => 'stock'],
            ));
        }
    }
}
