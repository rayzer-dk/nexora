<?php

declare(strict_types=1);

namespace Commerce\Modules\Director\Application;

use Doctrine\DBAL\Connection;

/**
 * The store director: every day it looks at the shop's own numbers and proposes at most three actions, most useful first. The rules are
 * plain and visible (no language model is needed, nothing leaves the shop). The only action that changes data is a reversible sale price
 * on products that have not sold for two months; the others take the owner to the place where the problem is.
 */
final class DirectorService
{
    public const MAX_PER_DAY = 3;
    private const SLOW_DAYS = 60;
    private const SLOW_LIMIT = 10;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{enabled:bool,discount_percent:int} */
    public function settings(int $storeId): array
    {
        $row = $this->db->fetchAssociative('SELECT enabled,discount_percent FROM mc_director_settings WHERE store_id=?', [$storeId]);

        return is_array($row) ? ['enabled' => (bool) $row['enabled'], 'discount_percent' => (int) $row['discount_percent']] : ['enabled' => false, 'discount_percent' => 10];
    }

    public function saveSettings(int $storeId, bool $enabled, int $percent): void
    {
        $this->db->executeStatement(
            'INSERT INTO mc_director_settings (store_id,enabled,discount_percent,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),discount_percent=VALUES(discount_percent),updated_at=VALUES(updated_at)',
            [$storeId, $enabled ? 1 : 0, max(1, min(50, $percent)), gmdate('Y-m-d H:i:s.u')],
        );
    }

    /** The actions of today (made on the first call of the day and kept, so the list does not change under the owner's hands). @return list<array<string,mixed>> */
    public function today(int $storeId): array
    {
        if (!$this->settings($storeId)['enabled']) {
            return [];
        }
        $day = gmdate('Y-m-d');
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_director_action WHERE store_id=? AND day=?', [$storeId, $day]) === 0) {
            foreach (self::pickTop($this->candidates($storeId), self::MAX_PER_DAY) as $c) {
                $this->db->executeStatement(
                    'INSERT IGNORE INTO mc_director_action (store_id,day,kind,status,params,created_at) VALUES (?,?,?,?,?,?)',
                    [$storeId, $day, $c['kind'], 'proposed', json_encode($c['params'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), gmdate('Y-m-d H:i:s.u')],
                );
            }
        }
        $rows = $this->db->fetchAllAssociative('SELECT id,kind,status,params,resolved_at FROM mc_director_action WHERE store_id=? AND day=? ORDER BY id', [$storeId, $day]);

        return array_map(static function (array $r): array {
            $params = json_decode((string) $r['params'], true);
            $params = is_array($params) ? $params : [];

            return ['id' => (int) $r['id'], 'kind' => (string) $r['kind'], 'status' => (string) $r['status'], 'params' => $params, 'can_apply' => $r['kind'] === 'slow_movers' && $r['status'] === 'proposed', 'can_undo' => $r['kind'] === 'slow_movers' && $r['status'] === 'applied'];
        }, $rows);
    }

    /**
     * @param list<array{kind:string,score:int,params:array<string,mixed>}> $candidates
     * @return list<array{kind:string,score:int,params:array<string,mixed>}>
     */
    public static function pickTop(array $candidates, int $limit): array
    {
        $candidates = array_values(array_filter($candidates, static fn (array $c): bool => $c['score'] > 0));
        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($candidates, 0, $limit);
    }

    /** @return list<array{kind:string,score:int,params:array<string,mixed>}> */
    private function candidates(int $storeId): array
    {
        $out = [];
        $slow = $this->slowMovers($storeId);
        if ($slow['count'] > 0) {
            $out[] = ['kind' => 'slow_movers', 'score' => min(1000, 100 + $slow['count'] * 20), 'params' => $slow + ['percent' => $this->settings($storeId)['discount_percent']]];
        }
        $inq = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_customer_inquiry WHERE store_id=? AND status='new' AND created_at<?", [$storeId, gmdate('Y-m-d H:i:s', time() - 86400)]);
        if ($inq > 0) {
            $out[] = ['kind' => 'stale_inquiries', 'score' => min(1500, 300 + $inq * 60), 'params' => ['count' => $inq]];
        }
        $unpaid = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_sales_order WHERE store_id=? AND payment_status='pending' AND status IN ('placed','awaiting_payment') AND created_at<? AND created_at>?", [$storeId, gmdate('Y-m-d H:i:s', time() - 86400), gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
        if ($unpaid > 0) {
            $out[] = ['kind' => 'unpaid_orders', 'score' => min(1200, 200 + $unpaid * 50), 'params' => ['count' => $unpaid]];
        }
        $low = $this->lowStockBestsellers($storeId);
        if ($low['count'] > 0) {
            $out[] = ['kind' => 'low_stock', 'score' => min(2000, 500 + $low['count'] * 80), 'params' => $low];
        }

        return $out;
    }

    /** @return array{count:int,names:list<string>,variant_ids:list<int>} in-stock products without a sale for two months, not newer than that */
    private function slowMovers(int $storeId): array
    {
        $market = (int) $this->db->fetchOne("SELECT id FROM mc_market WHERE store_id=? AND status='active' ORDER BY id LIMIT 1", [$storeId]);
        $rows = $this->db->fetchAllAssociative(
            "SELECT v.id,COALESCE((SELECT pt.name FROM mc_product_translation pt WHERE pt.product_id=v.product_id AND pt.store_id=:s ORDER BY pt.id LIMIT 1),v.sku) AS name,
                    (SELECT SUM(sl.stocked_quantity-sl.reserved_quantity) FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_market_inventory_location mil ON mil.location_id=sl.location_id AND mil.market_id=:m WHERE vii.variant_id=v.id) AS stock
             FROM mc_product_variant v JOIN mc_store_product sp ON sp.product_id=v.product_id AND sp.store_id=:s
             WHERE v.status='active' AND v.created_at<:cut
               AND NOT EXISTS (SELECT 1 FROM mc_sales_order_item i JOIN mc_sales_order o ON o.id=i.order_id WHERE i.variant_id=v.id AND o.created_at>=:cut)
               AND EXISTS (SELECT 1 FROM mc_price pr WHERE pr.variant_id=v.id AND pr.store_id=:s AND pr.market_id=:m AND pr.customer_group='default' AND pr.price_list_id IS NULL AND pr.max_quantity IS NULL AND pr.starts_at IS NULL AND pr.ends_at IS NULL AND pr.compare_at_minor IS NULL AND pr.amount_minor>0)
             HAVING stock>0 ORDER BY stock DESC LIMIT " . self::SLOW_LIMIT,
            ['s' => $storeId, 'm' => $market, 'cut' => gmdate('Y-m-d H:i:s', time() - self::SLOW_DAYS * 86400)],
        );

        return ['count' => count($rows), 'names' => array_map(static fn (array $r): string => mb_substr((string) $r['name'], 0, 60), array_slice($rows, 0, 3)), 'variant_ids' => array_map(static fn (array $r): int => (int) $r['id'], $rows)];
    }

    /** @return array{count:int,names:list<string>} products that sold well in 30 days and will run out within a week at that pace */
    private function lowStockBestsellers(int $storeId): array
    {
        $market = (int) $this->db->fetchOne("SELECT id FROM mc_market WHERE store_id=? AND status='active' ORDER BY id LIMIT 1", [$storeId]);
        $rows = $this->db->fetchAllAssociative(
            "SELECT v.id,COALESCE((SELECT pt.name FROM mc_product_translation pt WHERE pt.product_id=v.product_id AND pt.store_id=:s ORDER BY pt.id LIMIT 1),v.sku) AS name,
                    (SELECT SUM(i.quantity) FROM mc_sales_order_item i JOIN mc_sales_order o ON o.id=i.order_id WHERE i.variant_id=v.id AND o.store_id=:s AND o.status NOT IN ('cancelled') AND o.created_at>=:cut) AS sold,
                    (SELECT SUM(sl.stocked_quantity-sl.reserved_quantity) FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_market_inventory_location mil ON mil.location_id=sl.location_id AND mil.market_id=:m WHERE vii.variant_id=v.id) AS stock
             FROM mc_product_variant v JOIN mc_store_product sp ON sp.product_id=v.product_id AND sp.store_id=:s
             WHERE v.status='active' AND v.manage_inventory=1 HAVING sold>=5 AND stock IS NOT NULL AND stock<sold/30*7 ORDER BY sold DESC LIMIT 20",
            ['s' => $storeId, 'm' => $market, 'cut' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)],
        );

        return ['count' => count($rows), 'names' => array_map(static fn (array $r): string => mb_substr((string) $r['name'], 0, 60), array_slice($rows, 0, 3))];
    }

    /** Puts the sale price on the slow movers of the action; the old prices are kept so the action can be undone. */
    public function apply(int $storeId, int $id): int
    {
        return (int) $this->db->transactional(function (Connection $db) use ($storeId, $id): int {
            $action = $db->fetchAssociative("SELECT id,params FROM mc_director_action WHERE id=? AND store_id=? AND kind='slow_movers' AND status='proposed' FOR UPDATE", [$id, $storeId]);
            if (!is_array($action)) {
                throw new \DomainException('gone');
            }
            $params = json_decode((string) $action['params'], true);
            $percent = max(1, min(50, (int) ($params['percent'] ?? 10)));
            $market = (int) $db->fetchOne("SELECT id FROM mc_market WHERE store_id=? AND status='active' ORDER BY id LIMIT 1", [$storeId]);
            $undo = [];
            foreach ((array) ($params['variant_ids'] ?? []) as $variantId) {
                $price = $db->fetchAssociative(
                    "SELECT id,amount_minor FROM mc_price WHERE variant_id=? AND store_id=? AND market_id=? AND customer_group='default' AND price_list_id IS NULL AND max_quantity IS NULL AND starts_at IS NULL AND ends_at IS NULL AND compare_at_minor IS NULL AND amount_minor>0 ORDER BY priority,min_quantity,id DESC LIMIT 1 FOR UPDATE",
                    [(int) $variantId, $storeId, $market],
                );
                if (!is_array($price)) {
                    continue;
                }
                $old = (int) $price['amount_minor'];
                $new = max(1, (int) round($old * (100 - $percent) / 100));
                $db->executeStatement('UPDATE mc_price SET amount_minor=?,compare_at_minor=?,updated_at=? WHERE id=?', [$new, $old, gmdate('Y-m-d H:i:s.u'), (int) $price['id']]);
                $undo[] = ['price_id' => (int) $price['id'], 'old' => $old, 'new' => $new];
            }
            $db->executeStatement("UPDATE mc_director_action SET status='applied',undo_data=?,resolved_at=? WHERE id=?", [json_encode($undo, JSON_THROW_ON_ERROR), gmdate('Y-m-d H:i:s.u'), $id]);

            return count($undo);
        });
    }

    /** Gives the old prices back, except where somebody changed the price meanwhile. */
    public function undo(int $storeId, int $id): int
    {
        return (int) $this->db->transactional(function (Connection $db) use ($storeId, $id): int {
            $action = $db->fetchAssociative("SELECT id,undo_data FROM mc_director_action WHERE id=? AND store_id=? AND kind='slow_movers' AND status='applied' FOR UPDATE", [$id, $storeId]);
            if (!is_array($action)) {
                throw new \DomainException('gone');
            }
            $restored = 0;
            foreach ((array) json_decode((string) $action['undo_data'], true) as $u) {
                $restored += $db->executeStatement(
                    'UPDATE mc_price SET amount_minor=?,compare_at_minor=NULL,updated_at=? WHERE id=? AND amount_minor=? AND compare_at_minor=?',
                    [(int) $u['old'], gmdate('Y-m-d H:i:s.u'), (int) $u['price_id'], (int) $u['new'], (int) $u['old']],
                );
            }
            $db->executeStatement("UPDATE mc_director_action SET status='undone',resolved_at=? WHERE id=?", [gmdate('Y-m-d H:i:s.u'), $id]);

            return $restored;
        });
    }

    public function dismiss(int $storeId, int $id): void
    {
        $this->db->executeStatement("UPDATE mc_director_action SET status='dismissed',resolved_at=? WHERE id=? AND store_id=? AND status='proposed'", [gmdate('Y-m-d H:i:s.u'), $id, $storeId]);
    }
}
