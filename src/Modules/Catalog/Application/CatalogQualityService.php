<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * How complete the published catalogue is: pictures, texts, price, category, stock, SEO description and a text in
 * every store language. The core checks (image, description, price, category, translations) decide the score;
 * stock and SEO description are reported next to it.
 */
final readonly class CatalogQualityService
{
    public const CORE = ['no_image', 'no_description', 'no_price', 'no_category', 'missing_translations'];
    public const EXTRA = ['no_meta', 'no_stock'];

    public function __construct(private Connection $db)
    {
    }

    /** @return array{total:int,draft:int,clean:int,score:int,counts:array<string,int>,locales:list<array{code:string,name:string,complete:int,percent:int}>} */
    public function summary(int $storeId): array
    {
        $default = (string) $this->db->fetchOne('SELECT default_locale FROM mc_store WHERE id=?', [$storeId]);
        $inner = $this->flagged();
        $sums = [];
        foreach ([...self::CORE, ...self::EXTRA] as $flag) {
            $sums[] = "COALESCE(SUM(x.$flag>0),0) AS $flag";
        }
        $core = implode(' AND ', array_map(static fn (string $f): string => "x.$f=0", self::CORE));
        $row = $this->db->fetchAssociative("SELECT COUNT(*) AS total, COALESCE(SUM($core),0) AS clean, " . implode(',', $sums) . " FROM ($inner) x", ['s' => $storeId, 'l' => $default]) ?: [];
        $total = (int) ($row['total'] ?? 0);
        $clean = (int) ($row['clean'] ?? 0);
        $counts = [];
        foreach ([...self::CORE, ...self::EXTRA] as $flag) {
            $counts[$flag] = (int) ($row[$flag] ?? 0);
        }
        $draft = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? WHERE p.status='draft'", [$storeId]);

        $locales = [];
        foreach ($this->db->fetchAllAssociative('SELECT l.code,l.native_name FROM mc_store_locale sl JOIN mc_locale l ON l.code=sl.locale_code WHERE sl.store_id=? AND sl.enabled=1 ORDER BY sl.sort_order,l.code', [$storeId]) as $l) {
            $complete = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? WHERE p.status='published' AND EXISTS (SELECT 1 FROM mc_product_translation t WHERE t.product_id=p.id AND t.store_id=? AND t.locale=? AND t.name<>'' AND t.description IS NOT NULL AND t.description<>'')",
                [$storeId, $storeId, $l['code']],
            );
            $locales[] = ['code' => (string) $l['code'], 'name' => (string) $l['native_name'], 'complete' => $complete, 'percent' => $total > 0 ? (int) round($complete * 100 / $total) : 100];
        }

        return ['total' => $total, 'draft' => $draft, 'clean' => $clean, 'score' => $total > 0 ? (int) round($clean * 100 / $total) : 100, 'counts' => $counts, 'locales' => $locales];
    }

    /**
     * Products that have the given problem (or any core problem when $issue is empty).
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function products(int $storeId, string $issue, int $page, int $limit = 50): array
    {
        $default = (string) $this->db->fetchOne('SELECT default_locale FROM mc_store WHERE id=?', [$storeId]);
        $all = [...self::CORE, ...self::EXTRA];
        $where = in_array($issue, $all, true) ? "x.$issue>0" : '(' . implode(' OR ', array_map(static fn (string $f): string => "x.$f>0", self::CORE)) . ')';
        $inner = $this->flagged();
        $page = max(1, $page);
        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM ($inner) x WHERE $where", ['s' => $storeId, 'l' => $default]);
        $rows = $this->db->fetchAllAssociative("SELECT x.* FROM ($inner) x WHERE $where ORDER BY x.id DESC LIMIT " . $limit . ' OFFSET ' . (($page - 1) * $limit), ['s' => $storeId, 'l' => $default]);
        foreach ($rows as &$row) {
            $row['public_id'] = Uuid::fromBinary((string) $row['public_id'])->toRfc4122();
            $row['issues'] = array_values(array_filter($all, static fn (string $f): bool => (int) $row[$f] > 0));
        }
        unset($row);

        return ['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    private function flagged(): string
    {
        return "SELECT p.id, p.public_id, COALESCE(pt.name,'') AS name, v.sku,
            (SELECT COUNT(*) FROM mc_product_media pm WHERE pm.product_id=p.id)=0 AS no_image,
            (pt.description IS NULL OR pt.description='') AS no_description,
            (pt.meta_description IS NULL OR pt.meta_description='') AS no_meta,
            NOT EXISTS (SELECT 1 FROM mc_product_category pc WHERE pc.product_id=p.id) AS no_category,
            COALESCE((SELECT MAX(pr.amount_minor) FROM mc_price pr WHERE pr.variant_id=v.id AND pr.store_id=:s AND pr.customer_group='default' AND pr.price_list_id IS NULL),0)<=0 AS no_price,
            (v.manage_inventory=1 AND v.allow_backorder=0 AND COALESCE((SELECT SUM(GREATEST(sl.stocked_quantity-sl.reserved_quantity-sl.safety_stock,0)) FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id WHERE vii.variant_id=v.id),0)<=0) AS no_stock,
            (SELECT COUNT(*) FROM mc_store_locale sl2 WHERE sl2.store_id=:s AND sl2.enabled=1 AND NOT EXISTS (SELECT 1 FROM mc_product_translation t WHERE t.product_id=p.id AND t.store_id=:s AND t.locale=sl2.locale_code AND t.name<>'' AND t.description IS NOT NULL AND t.description<>'')) AS missing_translations
            FROM mc_product p
            JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=:s
            JOIN mc_product_variant v ON v.product_id=p.id AND v.sort_order=0
            LEFT JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=:s AND pt.locale=:l
            WHERE p.status='published'";
    }
}
