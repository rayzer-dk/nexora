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

        return [
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
