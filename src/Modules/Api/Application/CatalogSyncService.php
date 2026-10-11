<?php

declare(strict_types=1);

namespace Commerce\Modules\Api\Application;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Prices and stock written from outside (CRM, accounting, marketplace sync). One variant per call, found by its public id or by its SKU.
 * The rules are the ones of the supplier price lists: the default price row of the market and the stock of the market's first location.
 */
final class CatalogSyncService
{
    public const MAX_BATCH = 500;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{id:int,public_id:string,product_id:int,sku:string}|null */
    public function variant(int $storeId, ?string $publicId, ?string $sku): ?array
    {
        if ($publicId !== null && $publicId !== '' && Uuid::isValid($publicId)) {
            $where = 'v.public_id=?';
            $param = Uuid::fromString($publicId)->toBinary();
        } elseif ($sku !== null && trim($sku) !== '') {
            $where = 'v.sku=?';
            $param = trim($sku);
        } else {
            return null;
        }
        $row = $this->db->fetchAssociative(
            'SELECT v.id,v.public_id,v.product_id,v.sku FROM mc_product_variant v JOIN mc_store_product sp ON sp.product_id=v.product_id AND sp.store_id=? WHERE ' . $where . ' LIMIT 1',
            [$storeId, $param],
        );
        if (!is_array($row)) {
            return null;
        }

        return ['id' => (int) $row['id'], 'public_id' => Uuid::fromBinary((string) $row['public_id'])->toRfc4122(), 'product_id' => (int) $row['product_id'], 'sku' => (string) $row['sku']];
    }

    /**
     * Applies the sent fields of one item. Only the fields that are present change: price_minor, compare_at_minor (null clears it),
     * stock_quantity (a new absolute value) or stock_delta (added to the current stock).
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public function apply(int $storeId, int $marketId, string $currency, array $item): array
    {
        $variant = $this->variant(
            $storeId,
            isset($item['variant_public_id']) ? (string) $item['variant_public_id'] : (isset($item['public_id']) ? (string) $item['public_id'] : null),
            isset($item['sku']) ? (string) $item['sku'] : null,
        );
        if ($variant === null) {
            throw new \DomainException('variant_not_found');
        }
        $hasPrice = array_key_exists('price_minor', $item);
        $hasCompare = array_key_exists('compare_at_minor', $item);
        $hasStock = array_key_exists('stock_quantity', $item) || array_key_exists('stock_delta', $item);
        if (!$hasPrice && !$hasCompare && !$hasStock) {
            throw new \DomainException('nothing_to_change');
        }
        // Check every value before the first write, so a rejected item changes nothing.
        $price = $hasPrice ? $this->minor($item['price_minor'], 'price_minor') : null;
        $compare = $hasCompare && $item['compare_at_minor'] !== null ? $this->minor($item['compare_at_minor'], 'compare_at_minor') : null;
        $stock = $hasStock ? $this->stockValue($item) : null;

        $result = ['sku' => $variant['sku'], 'public_id' => $variant['public_id']];
        $now = gmdate('Y-m-d H:i:s.u');
        if ($hasPrice || $hasCompare) {
            $this->writePrice($variant['id'], $storeId, $marketId, $currency, $price, $hasCompare, $compare, $now);
            if ($price !== null) {
                $result['price_minor'] = $price;
            }
        }
        if ($stock !== null) {
            $result['stock_quantity'] = $this->writeStock($variant['id'], $marketId, $stock, $now);
        }

        return $result;
    }

    private function minor(mixed $value, string $field): int
    {
        if (is_string($value) && preg_match('/^\d{1,13}$/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 0 || $value > 9_000_000_000_000) {
            throw new \DomainException('invalid_' . $field);
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $item
     * @return array{absolute:bool,value:string}
     */
    private function stockValue(array $item): array
    {
        $absolute = array_key_exists('stock_quantity', $item);
        $raw = $absolute ? $item['stock_quantity'] : $item['stock_delta'];
        if (is_int($raw) || is_float($raw)) {
            $raw = (string) $raw;
        }
        $pattern = $absolute ? '/^\d{1,12}(?:\.\d{1,6})?$/D' : '/^-?\d{1,12}(?:\.\d{1,6})?$/D';
        if (!is_string($raw) || preg_match($pattern, $raw) !== 1) {
            throw new \DomainException($absolute ? 'invalid_stock_quantity' : 'invalid_stock_delta');
        }

        return ['absolute' => $absolute, 'value' => number_format((float) $raw, 6, '.', '')];
    }

    private function writePrice(int $variantId, int $storeId, int $marketId, string $currency, ?int $price, bool $hasCompare, ?int $compare, string $now): void
    {
        $id = $this->db->fetchOne(
            "SELECT id FROM mc_price WHERE variant_id=? AND store_id=? AND market_id=? AND currency=? AND customer_group='default' AND price_list_id IS NULL AND max_quantity IS NULL AND starts_at IS NULL AND ends_at IS NULL ORDER BY priority ASC,min_quantity ASC,id ASC LIMIT 1",
            [$variantId, $storeId, $marketId, $currency],
        );
        if ($id === false) {
            if ($price === null) {
                throw new \DomainException('price_row_missing');
            }
            $this->db->insert('mc_price', [
                'variant_id' => $variantId, 'store_id' => $storeId, 'price_list_id' => null, 'market_id' => $marketId, 'currency' => $currency, 'customer_group' => 'default',
                'min_quantity' => '1.000000', 'max_quantity' => null, 'amount_minor' => $price, 'compare_at_minor' => $compare, 'tax_included' => 1,
                'priority' => 100, 'starts_at' => null, 'ends_at' => null, 'source' => 'api', 'created_at' => $now, 'updated_at' => $now,
            ]);

            return;
        }
        $set = ['updated_at' => $now];
        if ($price !== null) {
            $set['amount_minor'] = $price;
        }
        if ($hasCompare) {
            $set['compare_at_minor'] = $compare;
        }
        $this->db->update('mc_price', $set, ['id' => (int) $id]);
        // An "old price" that is not higher than the price is no discount.
        $this->db->executeStatement('UPDATE mc_price SET compare_at_minor=NULL WHERE id=? AND compare_at_minor IS NOT NULL AND compare_at_minor<=amount_minor', [(int) $id]);
    }

    /** @param array{absolute:bool,value:string} $stock */
    private function writeStock(int $variantId, int $marketId, array $stock, string $now): string
    {
        $join = 'mc_stock_level sl
                 JOIN mc_variant_inventory_item vii ON vii.inventory_item_id=sl.inventory_item_id AND vii.variant_id=?
                 JOIN (SELECT mil.location_id FROM mc_market_inventory_location mil WHERE mil.market_id=? ORDER BY mil.priority,mil.location_id LIMIT 1) loc ON loc.location_id=sl.location_id';
        $set = $stock['absolute'] ? 'sl.stocked_quantity=?' : 'sl.stocked_quantity=GREATEST(sl.stocked_quantity+?,0)';
        $changed = $this->db->executeStatement(
            'UPDATE ' . $join . ' SET ' . $set . ',sl.row_version=sl.row_version+1,sl.updated_at=?',
            [$variantId, $marketId, $stock['value'], $now],
        );
        $current = $this->db->fetchOne('SELECT sl.stocked_quantity FROM ' . $join . ' LIMIT 1', [$variantId, $marketId]);
        if ($changed < 1 && $current === false) {
            throw new \DomainException('stock_row_missing');
        }

        return rtrim(rtrim((string) $current, '0'), '.');
    }
}
