<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;

/**
 * Promotion side of a product: a dated sale price, related / "bought together" products by SKU, tags,
 * an own canonical URL and Google Merchant custom labels.
 */
final class ProductExtrasService
{
    public const RELATIONS = ['related', 'complementary'];
    private const SALE_PRIORITY = 10;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string,mixed> */
    public function forAdmin(int $productId, int $variantId, int $storeId, int $marketId): array
    {
        $extra = $this->db->fetchAssociative('SELECT * FROM mc_product_extra WHERE product_id=?', [$productId]) ?: [];
        $labels = [];
        for ($i = 0; $i < 5; ++$i) {
            $labels[$i] = (string) ($extra['custom_label_' . $i] ?? '');
        }
        $skus = [];
        foreach (self::RELATIONS as $type) {
            $skus[$type] = implode(', ', array_map('strval', $this->db->fetchFirstColumn(
                'SELECT v.sku FROM mc_product_relation r JOIN mc_product_variant v ON v.product_id=r.related_product_id AND v.sort_order=0 WHERE r.product_id=? AND r.relation_type=? ORDER BY r.sort_order,r.related_product_id',
                [$productId, $type],
            )));
        }
        $sale = $this->saleRow($variantId, $storeId, $marketId);
        $published = $this->db->fetchOne('SELECT published_at FROM mc_store_product WHERE product_id=? AND store_id=?', [$productId, $storeId]);

        return [
            'cost' => ($extra['cost_minor'] ?? null) !== null ? number_format(((int) $extra['cost_minor']) / 100, 2, '.', '') : '',
            'available_from' => is_string($published) && $published !== '' && $published > gmdate('Y-m-d H:i:s') ? $this->toLocalInput($published) : '',
            'tiers' => $this->tiersText($variantId, $storeId, $marketId),
            'canonical_url' => (string) ($extra['canonical_url'] ?? ''),
            'tags' => (string) ($extra['tags'] ?? ''),
            'labels' => $labels,
            'related' => $skus['related'],
            'complementary' => $skus['complementary'],
            'sale_price' => is_array($sale) ? number_format(((int) $sale['amount_minor']) / 100, 2, '.', '') : '',
            'sale_starts' => is_array($sale) && $sale['starts_at'] !== null ? $this->toLocalInput((string) $sale['starts_at']) : '',
            'sale_ends' => is_array($sale) && $sale['ends_at'] !== null ? $this->toLocalInput((string) $sale['ends_at']) : '',
        ];
    }

    /** @param array<string,mixed> $in */
    public function save(int $productId, int $variantId, int $storeId, int $marketId, string $currency, array $in): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $canonical = trim((string) ($in['canonical_url'] ?? ''));
        if ($canonical !== '' && (preg_match('#^https?://#i', $canonical) !== 1 || filter_var($canonical, FILTER_VALIDATE_URL) === false || mb_strlen($canonical) > 500)) {
            throw new \DomainException(CanonicalUiText::get('admin.product.extras.canonical_invalid'));
        }
        $tags = $this->normalizeTags((string) ($in['tags'] ?? ''));
        $row = ['canonical_url' => $canonical !== '' ? $canonical : null, 'tags' => $tags !== '' ? $tags : null, 'updated_at' => $now];
        if (array_key_exists('cost', $in)) {
            $cost = trim(str_replace(',', '.', (string) $in['cost']));
            if ($cost !== '' && preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', $cost) !== 1) {
                throw new \DomainException(CanonicalUiText::get('admin.product.extras.cost_invalid'));
            }
            $row['cost_minor'] = $cost === '' ? null : (int) round(((float) $cost) * 100);
        }
        $labels = is_array($in['labels'] ?? null) ? $in['labels'] : [];
        for ($i = 0; $i < 5; ++$i) {
            $v = trim(mb_substr(strip_tags((string) ($labels[$i] ?? '')), 0, 100, 'UTF-8'));
            $row['custom_label_' . $i] = $v !== '' ? $v : null;
        }
        if ($this->db->fetchOne('SELECT 1 FROM mc_product_extra WHERE product_id=?', [$productId])) {
            $this->db->update('mc_product_extra', $row, ['product_id' => $productId]);
        } else {
            $this->db->insert('mc_product_extra', $row + ['product_id' => $productId]);
        }

        foreach (self::RELATIONS as $type) {
            if (array_key_exists($type, $in)) {
                $this->replaceRelations($productId, $type, (string) $in[$type], $now);
            }
        }
        if (array_key_exists('available_from', $in)) {
            $from = $this->fromLocalInput((string) $in['available_from']);
            $this->db->executeStatement('UPDATE mc_store_product SET published_at=? WHERE product_id=? AND store_id=?', [$from !== null && $from > $now ? $from : $now, $productId, $storeId]);
        }
        if (array_key_exists('tiers', $in)) {
            $this->saveTiers($variantId, $storeId, $marketId, $currency, (string) $in['tiers'], $now);
        }
        if (array_key_exists('sale_price', $in)) {
            $this->saveSale($variantId, $storeId, $marketId, $currency, (string) $in['sale_price'], (string) ($in['sale_starts'] ?? ''), (string) ($in['sale_ends'] ?? ''), $now);
        }
    }

    /** @return array{canonical_url:?string,tags:list<string>,labels:list<string>} */
    public function forStorefront(int $productId): array
    {
        $extra = $this->db->fetchAssociative('SELECT * FROM mc_product_extra WHERE product_id=?', [$productId]) ?: [];

        return [
            'canonical_url' => ($extra['canonical_url'] ?? null) ?: null,
            'tags' => array_values(array_filter(array_map('trim', explode(',', (string) ($extra['tags'] ?? ''))))),
            'labels' => array_values(array_filter(array_map(static fn (int $i): string => (string) ($extra['custom_label_' . $i] ?? ''), range(0, 4)))),
        ];
    }

    /** "5=450, 10=420" — from 5 pcs the unit price is 450, from 10 pcs 420. */
    private function tiersText(int $variantId, int $storeId, int $marketId): string
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT min_quantity,amount_minor FROM mc_price WHERE variant_id=? AND store_id=? AND market_id=? AND customer_group='default' AND price_list_id IS NULL AND min_quantity>1 AND starts_at IS NULL AND ends_at IS NULL AND priority=100 ORDER BY min_quantity",
            [$variantId, $storeId, $marketId],
        );

        return implode(', ', array_map(static fn (array $r): string => rtrim(rtrim((string) $r['min_quantity'], '0'), '.') . '=' . number_format(((int) $r['amount_minor']) / 100, 2, '.', ''), $rows));
    }

    private function saveTiers(int $variantId, int $storeId, int $marketId, string $currency, string $raw, string $now): void
    {
        $base = $this->db->fetchOne(
            "SELECT amount_minor FROM mc_price WHERE variant_id=? AND store_id=? AND market_id=? AND customer_group='default' AND price_list_id IS NULL AND max_quantity IS NULL AND starts_at IS NULL AND ends_at IS NULL AND min_quantity<=1 ORDER BY priority ASC,id DESC LIMIT 1",
            [$variantId, $storeId, $marketId],
        );
        $tiers = [];
        foreach (preg_split('/[,;\n]+/', $raw) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(\d{1,6})\s*[=:]\s*(\d{1,9}(?:[.,]\d{1,2})?)$/', $part, $m) !== 1) {
                throw new \DomainException(CanonicalUiText::get('admin.product.extras.tiers_invalid'));
            }
            $qty = (int) $m[1];
            $minor = (int) round(((float) str_replace(',', '.', $m[2])) * 100);
            if ($qty < 2 || $minor <= 0 || ($base !== false && $minor >= (int) $base)) {
                throw new \DomainException(CanonicalUiText::get('admin.product.extras.tiers_invalid'));
            }
            $tiers[$qty] = $minor;
        }
        ksort($tiers);
        $prev = null;
        foreach ($tiers as $minor) {
            if ($prev !== null && $minor >= $prev) {
                throw new \DomainException(CanonicalUiText::get('admin.product.extras.tiers_invalid'));
            }
            $prev = $minor;
        }
        $this->db->executeStatement(
            "DELETE FROM mc_price WHERE variant_id=? AND store_id=? AND market_id=? AND customer_group='default' AND price_list_id IS NULL AND min_quantity>1 AND starts_at IS NULL AND ends_at IS NULL AND priority=100",
            [$variantId, $storeId, $marketId],
        );
        foreach (array_slice($tiers, 0, 8, true) as $qty => $minor) {
            $this->db->insert('mc_price', [
                'variant_id' => $variantId, 'store_id' => $storeId, 'price_list_id' => null, 'market_id' => $marketId, 'currency' => $currency, 'customer_group' => 'default',
                'min_quantity' => number_format($qty, 6, '.', ''), 'max_quantity' => null, 'amount_minor' => $minor, 'compare_at_minor' => null, 'tax_included' => 1,
                'priority' => 100, 'starts_at' => null, 'ends_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /** @return list<array{quantity:int,minor:int}> */
    public function tiersForStorefront(int $productId, int $storeId, string $currency): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT px.min_quantity,MIN(px.amount_minor) amount_minor FROM mc_price px JOIN mc_product_variant v ON v.id=px.variant_id AND v.product_id=? AND v.sort_order=0 WHERE px.store_id=? AND px.currency=? AND px.customer_group='default' AND px.price_list_id IS NULL AND px.min_quantity>1 AND (px.starts_at IS NULL OR px.starts_at<=UTC_TIMESTAMP(6)) AND (px.ends_at IS NULL OR px.ends_at>UTC_TIMESTAMP(6)) GROUP BY px.min_quantity ORDER BY px.min_quantity",
            [$productId, $storeId, $currency],
        );

        return array_map(static fn (array $r): array => ['quantity' => (int) $r['min_quantity'], 'minor' => (int) $r['amount_minor']], $rows);
    }

    /** Unit price for a quantity: the best retail volume tier at or below it, never above the current price. */
    public function tierPrice(int $variantId, int $storeId, string $currency, string $quantity, int $unitMinor): int
    {
        $tier = $this->db->fetchOne(
            "SELECT amount_minor FROM mc_price WHERE variant_id=? AND store_id=? AND currency=? AND customer_group='default' AND price_list_id IS NULL AND min_quantity>1 AND min_quantity<=? AND (max_quantity IS NULL OR max_quantity>=?) AND (starts_at IS NULL OR starts_at<=UTC_TIMESTAMP(6)) AND (ends_at IS NULL OR ends_at>UTC_TIMESTAMP(6)) ORDER BY min_quantity DESC,id DESC LIMIT 1",
            [$variantId, $storeId, $currency, $quantity, $quantity],
        );

        return $tier === false ? $unitMinor : min($unitMinor, (int) $tier);
    }

    private function normalizeTags(string $raw): string
    {
        $seen = [];
        foreach (preg_split('/[,\n;]+/u', $raw) ?: [] as $t) {
            $t = trim(mb_substr(strip_tags($t), 0, 40, 'UTF-8'));
            if ($t !== '') {
                $seen[mb_strtolower($t, 'UTF-8')] = $t;
            }
        }

        return mb_substr(implode(', ', array_slice(array_values($seen), 0, 20)), 0, 500, 'UTF-8');
    }

    private function replaceRelations(int $productId, string $type, string $rawSkus, string $now): void
    {
        $this->db->delete('mc_product_relation', ['product_id' => $productId, 'relation_type' => $type]);
        $order = 0;
        foreach (array_slice(array_unique(array_filter(array_map('trim', preg_split('/[,\s;]+/', $rawSkus) ?: []))), 0, 24) as $sku) {
            $id = $this->db->fetchOne('SELECT product_id FROM mc_product_variant WHERE sku=? LIMIT 1', [$sku]);
            if ($id === false || (int) $id === $productId) {
                continue;
            }
            $this->db->executeStatement(
                'INSERT IGNORE INTO mc_product_relation (product_id,related_product_id,relation_type,sort_order,created_at) VALUES (?,?,?,?,?)',
                [$productId, (int) $id, $type, ++$order, $now],
            );
        }
    }

    private function saveSale(int $variantId, int $storeId, int $marketId, string $currency, string $price, string $starts, string $ends, string $now): void
    {
        $existing = $this->saleRow($variantId, $storeId, $marketId);
        $price = trim(str_replace(',', '.', $price));
        if ($price === '') {
            if (is_array($existing)) {
                $this->db->delete('mc_price', ['id' => (int) $existing['id']]);
            }

            return;
        }
        if (preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', $price) !== 1) {
            throw new \DomainException(CanonicalUiText::get('admin.product.extras.sale_invalid'));
        }
        $minor = (int) round(((float) $price) * 100);
        $base = $this->db->fetchAssociative(
            "SELECT amount_minor FROM mc_price WHERE variant_id=? AND store_id=? AND market_id=? AND customer_group='default' AND price_list_id IS NULL AND max_quantity IS NULL AND starts_at IS NULL AND ends_at IS NULL ORDER BY priority ASC,min_quantity ASC,id DESC LIMIT 1",
            [$variantId, $storeId, $marketId],
        );
        if (!is_array($base) || $minor >= (int) $base['amount_minor'] || $minor <= 0) {
            throw new \DomainException(CanonicalUiText::get('admin.product.extras.sale_not_lower'));
        }
        $startsAt = $this->fromLocalInput($starts) ?? $now;
        $endsAt = $this->fromLocalInput($ends);
        if ($endsAt !== null && $endsAt <= $startsAt) {
            throw new \DomainException(CanonicalUiText::get('admin.product.extras.sale_dates'));
        }
        $data = ['amount_minor' => $minor, 'compare_at_minor' => (int) $base['amount_minor'], 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'currency' => $currency, 'updated_at' => $now];
        if (is_array($existing)) {
            $this->db->update('mc_price', $data, ['id' => (int) $existing['id']]);
        } else {
            $this->db->insert('mc_price', $data + [
                'variant_id' => $variantId, 'store_id' => $storeId, 'price_list_id' => null, 'market_id' => $marketId, 'customer_group' => 'default',
                'min_quantity' => '1.000000', 'max_quantity' => null, 'tax_included' => 1, 'priority' => self::SALE_PRIORITY, 'created_at' => $now,
            ]);
        }
    }

    /** @return array<string,mixed>|false */
    private function saleRow(int $variantId, int $storeId, int $marketId): array|false
    {
        return $this->db->fetchAssociative(
            "SELECT id,amount_minor,starts_at,ends_at FROM mc_price WHERE variant_id=? AND store_id=? AND market_id=? AND customer_group='default' AND price_list_id IS NULL AND priority=? AND starts_at IS NOT NULL ORDER BY id DESC LIMIT 1",
            [$variantId, $storeId, $marketId, self::SALE_PRIORITY],
        );
    }

    private function toLocalInput(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d\TH:i');
    }

    private function fromLocalInput(string $local): ?string
    {
        $local = trim($local);
        if ($local === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($local, new \DateTimeZone(date_default_timezone_get())))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        } catch (\Throwable) {
            throw new \DomainException(CanonicalUiText::get('admin.product.extras.sale_dates'));
        }
    }
}
