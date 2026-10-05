<?php

declare(strict_types=1);

namespace Commerce\Modules\Analytics\Application;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Plain-language suggestions built from what shoppers really do: products that are looked at but never bought,
 * products that sit in stock without selling, and pairs that are bought together.
 */
final readonly class ProductInsightsService
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array{viewed_not_bought:list<array<string,mixed>>,slow_movers:list<array<string,mixed>>,pairs:list<array<string,mixed>>} */
    public function report(int $storeId, string $locale): array
    {
        return [
            'viewed_not_bought' => $this->viewedNotBought($storeId, $locale),
            'slow_movers' => $this->slowMovers($storeId, $locale),
            'pairs' => $this->pairs($storeId, $locale),
        ];
    }

    /** @return list<array{name:string,sku:string,public_id:string,views:int,carts:int}> */
    private function viewedNotBought(int $storeId, string $locale): array
    {
        $since = gmdate('Y-m-d', time() - 30 * 86400);
        $views = $this->db->fetchAllKeyValue('SELECT path,SUM(views) FROM mc_analytics_page_daily WHERE store_id=? AND day>=? GROUP BY path HAVING SUM(views)>=10', [$storeId, $since]);
        if ($views === []) {
            return [];
        }
        $sold = $this->db->fetchAllKeyValue("SELECT oi.product_id,SUM(oi.quantity) FROM mc_sales_order_item oi JOIN mc_sales_order o ON o.id=oi.order_id WHERE o.store_id=? AND o.created_at>=? AND o.status NOT IN ('cancelled','expired') GROUP BY oi.product_id", [$storeId, gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        $rows = $this->db->fetchAllAssociative(
            "SELECT p.id,p.public_id,s.path,COALESCE(pt.name,'') name,(SELECT MIN(v.sku) FROM mc_product_variant v WHERE v.product_id=p.id) sku
             FROM mc_seo_route s JOIN mc_product p ON p.public_id=s.entity_public_id AND p.status='published'
             JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=s.store_id
             LEFT JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=s.store_id AND pt.locale=s.locale
             WHERE s.store_id=? AND s.entity_type='product' AND s.locale=?",
            [$storeId, $locale],
        );
        $out = [];
        foreach ($rows as $r) {
            $v = (int) ($views['/' . ltrim((string) $r['path'], '/')] ?? 0);
            if ($v < 10 || (float) ($sold[$r['id']] ?? 0) > 0) {
                continue;
            }
            $out[] = ['name' => (string) $r['name'], 'sku' => (string) $r['sku'], 'public_id' => Uuid::fromBinary((string) $r['public_id'])->toRfc4122(), 'views' => $v, 'carts' => 0];
        }
        usort($out, static fn (array $a, array $b): int => $b['views'] <=> $a['views']);

        return array_slice($out, 0, 15);
    }

    /** @return list<array{name:string,sku:string,public_id:string,stock:int,age_days:int}> */
    private function slowMovers(int $storeId, string $locale): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT p.id,p.public_id,p.created_at,COALESCE(pt.name,'') name,MIN(v.sku) sku,SUM(sl.stocked_quantity-sl.reserved_quantity-sl.safety_stock) stock
             FROM mc_product p
             JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=?
             JOIN mc_product_variant v ON v.product_id=p.id
             JOIN mc_variant_inventory_item vii ON vii.variant_id=v.id
             JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id
             LEFT JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=sp.store_id AND pt.locale=?
             WHERE p.status='published' AND p.created_at<?
               AND NOT EXISTS (SELECT 1 FROM mc_sales_order_item oi JOIN mc_sales_order o ON o.id=oi.order_id WHERE oi.product_id=p.id AND o.created_at>=? AND o.status NOT IN ('cancelled','expired'))
             GROUP BY p.id,p.public_id,p.created_at,pt.name
             HAVING stock>0
             ORDER BY stock DESC LIMIT 15",
            [$storeId, $locale, gmdate('Y-m-d H:i:s', time() - 60 * 86400), gmdate('Y-m-d H:i:s', time() - 90 * 86400)],
        );

        return array_map(static fn (array $r): array => [
            'name' => (string) $r['name'], 'sku' => (string) $r['sku'], 'public_id' => Uuid::fromBinary((string) $r['public_id'])->toRfc4122(),
            'stock' => (int) $r['stock'], 'age_days' => max(0, (int) floor((time() - strtotime((string) $r['created_at'])) / 86400)),
        ], $rows);
    }

    /** @return list<array{a:string,b:string,a_id:string,b_id:string,orders:int}> */
    private function pairs(int $storeId, string $locale): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT a.product_id a_id,b.product_id b_id,COUNT(DISTINCT a.order_id) orders
             FROM mc_sales_order_item a
             JOIN mc_sales_order_item b ON b.order_id=a.order_id AND b.product_id>a.product_id
             JOIN mc_sales_order o ON o.id=a.order_id
             WHERE o.store_id=? AND o.created_at>=? AND o.status NOT IN ('cancelled','expired')
             GROUP BY a.product_id,b.product_id HAVING orders>=2 ORDER BY orders DESC LIMIT 10",
            [$storeId, gmdate('Y-m-d H:i:s', time() - 90 * 86400)],
        );
        if ($rows === []) {
            return [];
        }
        $ids = array_unique(array_merge(array_column($rows, 'a_id'), array_column($rows, 'b_id')));
        $in = implode(',', array_map('intval', $ids));
        $names = $this->db->fetchAllKeyValue("SELECT p.id,COALESCE(pt.name,'') FROM mc_product p LEFT JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=? AND pt.locale=? WHERE p.id IN ($in)", [$storeId, $locale]);
        $publics = $this->db->fetchAllKeyValue("SELECT id,public_id FROM mc_product WHERE id IN ($in)");

        return array_map(static fn (array $r): array => [
            'a' => (string) ($names[$r['a_id']] ?? ''), 'b' => (string) ($names[$r['b_id']] ?? ''),
            'a_id' => Uuid::fromBinary((string) $publics[$r['a_id']])->toRfc4122(), 'b_id' => Uuid::fromBinary((string) $publics[$r['b_id']])->toRfc4122(),
            'orders' => (int) $r['orders'],
        ], $rows);
    }
}
