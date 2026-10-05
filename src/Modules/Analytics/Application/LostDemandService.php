<?php

declare(strict_types=1);

namespace Commerce\Modules\Analytics\Application;

use Doctrine\DBAL\Connection;

/**
 * Products that cannot be bought right now but are wanted: views of their page and the shoppers waiting for them
 * ("tell me when it is back"). The list is ordered by how much demand is lost, so it tells what to restock first.
 */
final readonly class LostDemandService
{
    public function __construct(private Connection $db)
    {
    }

    /** @return list<array{product_id:int,public_id:string,name:string,sku:string,views:int,waiting:int,avg_price_minor:int,lost_minor:int,score:int,path:string}> */
    public function report(int $storeId, string $locale, int $limit = 50): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT p.id product_id,p.public_id,COALESCE(pt.name,'') name,MIN(v.sku) sku
             FROM mc_product p
             JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=?
             JOIN mc_product_variant v ON v.product_id=p.id
             JOIN mc_variant_inventory_item vii ON vii.variant_id=v.id
             JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id
             LEFT JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=sp.store_id AND pt.locale=?
             WHERE p.status='published' AND v.manage_inventory=1 AND v.allow_backorder=0
             GROUP BY p.id,p.public_id,pt.name
             HAVING SUM(sl.stocked_quantity-sl.reserved_quantity-sl.safety_stock)<=0
             LIMIT 500",
            [$storeId, $locale],
        );
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['product_id'], $rows);
        $in = implode(',', $ids);
        $waiting = $this->db->fetchAllKeyValue("SELECT product_id,COUNT(DISTINCT email_normalized) FROM mc_stock_notification_request WHERE store_id=? AND status IN ('pending','active') AND product_id IN ($in) GROUP BY product_id", [$storeId]);
        $avg = $this->db->fetchAllKeyValue("SELECT product_id,ROUND(AVG(unit_price_minor)) FROM mc_sales_order_item WHERE product_id IN ($in) GROUP BY product_id");
        $paths = $this->db->fetchAllKeyValue("SELECT entity_public_id,path FROM mc_seo_route WHERE store_id=? AND entity_type='product' AND locale=?", [$storeId, $locale]);
        $since = gmdate('Y-m-d', time() - 30 * 86400);
        $viewsByPath = $this->db->fetchAllKeyValue('SELECT path,SUM(views) FROM mc_analytics_page_daily WHERE store_id=? AND day>=? GROUP BY path', [$storeId, $since]);

        $out = [];
        foreach ($rows as $r) {
            $pid = (int) $r['product_id'];
            $seo = (string) ($paths[$r['public_id']] ?? '');
            $path = $seo !== '' ? '/' . ltrim($seo, '/') : '';
            $views = 0;
            foreach ($viewsByPath as $viewPath => $count) {
                if ($path !== '' && (string) $viewPath === $path) {
                    $views = (int) $count;
                    break;
                }
            }
            $wait = (int) ($waiting[$pid] ?? 0);
            if ($views === 0 && $wait === 0) {
                continue;
            }
            $price = (int) ($avg[$pid] ?? 0);
            $out[] = [
                'product_id' => $pid, 'public_id' => \Symfony\Component\Uid\Uuid::fromBinary((string) $r['public_id'])->toRfc4122(),
                'name' => (string) $r['name'], 'sku' => (string) $r['sku'], 'views' => $views, 'waiting' => $wait,
                'avg_price_minor' => $price, 'lost_minor' => $wait * $price, 'score' => $views + $wait * 20, 'path' => $path,
            ];
        }
        usort($out, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($out, 0, max(1, min(200, $limit)));
    }

    public function waitingTotal(int $storeId): int
    {
        return (int) $this->db->fetchOne("SELECT COUNT(DISTINCT CONCAT(product_id,':',email_normalized)) FROM mc_stock_notification_request WHERE store_id=? AND status IN ('pending','active')", [$storeId]);
    }
}
