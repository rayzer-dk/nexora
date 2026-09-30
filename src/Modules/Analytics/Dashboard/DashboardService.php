<?php

declare(strict_types=1);

namespace Commerce\Modules\Analytics\Dashboard;

use Doctrine\DBAL\Connection;

/**
 * Read model of the admin dashboard. Every figure is computed from real order, cart, stock and
 * enquiry rows for one store; nothing is estimated except the explicit "pace" projection and the
 * stock cover, which are labelled as such in the UI.
 */
final readonly class DashboardService
{
    private const PAID = "('paid','partially_refunded','refunded')";

    public function __construct(private Connection $db) {}

    /** @return array<string,mixed> */
    public function build(int $storeId, int $days): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $store = $this->db->fetchAssociative('SELECT default_currency,timezone FROM mc_store WHERE id=?', [$storeId]) ?: [];
        $tzName = (string) ($store['timezone'] ?? 'UTC');
        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
        }
        $offset = $this->offset($tz);
        $todayLocal = new \DateTimeImmutable('today', $tz);
        $fromLocal = $todayLocal->modify('-' . ($days - 1) . ' days');
        $prevFromLocal = $fromLocal->modify('-' . $days . ' days');
        $utc = new \DateTimeZone('UTC');
        $since = $fromLocal->setTimezone($utc)->format('Y-m-d H:i:s');
        $prevSince = $prevFromLocal->setTimezone($utc)->format('Y-m-d H:i:s');
        $until = $todayLocal->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');

        $current = $this->period($storeId, $since, $until);
        $previous = $this->period($storeId, $prevSince, $since);

        $daily = $this->daily($storeId, $since, $until, $offset);
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $fromLocal->modify('+' . $i . ' days')->format('Y-m-d');
            $series[] = ['day' => $d, 'revenue_minor' => (int) ($daily[$d]['revenue'] ?? 0), 'orders' => (int) ($daily[$d]['orders'] ?? 0)];
        }
        $annotations = $this->db->fetchAllAssociative('SELECT id,day,note FROM mc_dashboard_annotation WHERE store_id=? AND day>=? ORDER BY day,id', [$storeId, $fromLocal->format('Y-m-d')]);

        return [
            'days' => $days,
            'currency' => (string) ($store['default_currency'] ?? 'UAH'),
            'timezone' => $tz->getName(),
            'kpis' => $this->kpis($current, $previous),
            'series' => $series,
            'annotations' => $annotations,
            'goals' => $this->goals($storeId, $tz),
            'funnel' => $this->funnel($storeId, $since, $until, $current),
            'traffic' => (new \Commerce\Modules\Analytics\Visit\VisitReport($this->db))->totals($storeId, $since, $until),
            'pipeline' => $this->pipeline($storeId),
            'top_products' => $this->topProducts($storeId, $since, $until),
            'reorder' => $this->reorder($storeId),
            'sources' => $this->sources($storeId, $since, $until),
            'zero_searches' => $this->db->fetchAllAssociative('SELECT query_text,COUNT(*) searches FROM mc_search_query_log WHERE store_id=? AND created_at>=? AND result_count=0 GROUP BY query_hash,query_text ORDER BY searches DESC LIMIT 5', [$storeId, $since]),
            'recent_orders' => $this->db->fetchAllAssociative('SELECT order_number,customer_name,total_minor,currency,status,payment_status,created_at FROM mc_sales_order WHERE store_id=? ORDER BY id DESC LIMIT 8', [$storeId]),
            'inquiries' => $this->db->fetchAllAssociative("SELECT id,public_id,inquiry_type,customer_name,phone,created_at FROM mc_customer_inquiry WHERE store_id=? AND status IN ('new','in_progress') ORDER BY id DESC LIMIT 5", [$storeId]),
        ];
    }

    /** @return array{orders:int,paid:int,gross:int,refund:int,customers:int,new_customers:int,carts:int} */
    private function period(int $storeId, string $from, string $to): array
    {
        $o = $this->db->fetchAssociative(
            'SELECT COUNT(*) orders,SUM(payment_status IN ' . self::PAID . ') paid,COALESCE(SUM(CASE WHEN payment_status IN ' . self::PAID . " THEN total_minor ELSE 0 END),0) gross FROM mc_sales_order WHERE store_id=? AND status NOT IN ('cancelled') AND created_at>=? AND created_at<?",
            [$storeId, $from, $to],
        ) ?: [];
        $refund = (int) $this->db->fetchOne("SELECT COALESCE(SUM(r.amount_minor),0) FROM mc_payment_refund r JOIN mc_payment p ON p.id=r.payment_id JOIN mc_sales_order o ON o.id=p.order_id WHERE o.store_id=? AND r.status='succeeded' AND r.created_at>=? AND r.created_at<?", [$storeId, $from, $to]);
        $newCustomers = (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_customer c JOIN mc_store_customer m ON m.customer_id=c.id AND m.store_id=? WHERE c.created_at>=? AND c.created_at<?', [$storeId, $from, $to]);
        $carts = (int) $this->db->fetchOne('SELECT COUNT(DISTINCT c.id) FROM mc_cart c JOIN mc_cart_item ci ON ci.cart_id=c.id WHERE c.store_id=? AND c.created_at>=? AND c.created_at<?', [$storeId, $from, $to]);

        return ['orders' => (int) ($o['orders'] ?? 0), 'paid' => (int) ($o['paid'] ?? 0), 'gross' => (int) ($o['gross'] ?? 0), 'refund' => $refund, 'customers' => 0, 'new_customers' => $newCustomers, 'carts' => $carts];
    }

    /**
     * @param array<string,int> $c
     * @param array<string,int> $p
     * @return list<array{key:string,value:int|float,previous:int|float,delta:?float,kind:string}>
     */
    private function kpis(array $c, array $p): array
    {
        $net = static fn (array $x): int => max(0, $x['gross'] - $x['refund']);
        $aov = static fn (array $x): int => $x['paid'] > 0 ? (int) round(max(0, $x['gross'] - $x['refund']) / $x['paid']) : 0;
        $conv = static fn (array $x): float => $x['carts'] > 0 ? round($x['orders'] / $x['carts'] * 100, 1) : 0.0;
        $rows = [
            ['revenue', $net($c), $net($p), 'money'],
            ['orders', $c['orders'], $p['orders'], 'int'],
            ['aov', $aov($c), $aov($p), 'money'],
            ['conversion', $conv($c), $conv($p), 'pct'],
            ['new_customers', $c['new_customers'], $p['new_customers'], 'int'],
            ['refunds', $c['refund'], $p['refund'], 'money'],
        ];
        $out = [];
        foreach ($rows as [$key, $value, $previous, $kind]) {
            $out[] = ['key' => $key, 'value' => $value, 'previous' => $previous, 'kind' => $kind, 'delta' => $previous > 0 ? round((($value - $previous) / $previous) * 100, 1) : null];
        }

        return $out;
    }

    /** @return array<string,array{revenue:int,orders:int}> */
    private function daily(int $storeId, string $from, string $to, string $offset): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT DATE(CONVERT_TZ(created_at,\'+00:00\',?)) d,COUNT(*) orders,COALESCE(SUM(CASE WHEN payment_status IN ' . self::PAID . " THEN total_minor ELSE 0 END),0) revenue FROM mc_sales_order WHERE store_id=? AND status NOT IN ('cancelled') AND created_at>=? AND created_at<? GROUP BY d",
            [$offset, $storeId, $from, $to],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['d']] = ['revenue' => (int) $r['revenue'], 'orders' => (int) $r['orders']];
        }

        return $out;
    }

    /** Month-to-date goals with a linear pace projection. @return array<string,mixed> */
    private function goals(int $storeId, \DateTimeZone $tz): array
    {
        $targets = $this->db->fetchAllKeyValue('SELECT metric,target FROM mc_dashboard_goal WHERE store_id=?', [$storeId]);
        $now = new \DateTimeImmutable('now', $tz);
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);
        $daysInMonth = (int) $now->format('t');
        $dayOfMonth = (int) $now->format('j');
        $utc = new \DateTimeZone('UTC');
        $from = $monthStart->setTimezone($utc)->format('Y-m-d H:i:s');
        $row = $this->db->fetchAssociative(
            'SELECT COUNT(*) orders,COALESCE(SUM(CASE WHEN payment_status IN ' . self::PAID . " THEN total_minor ELSE 0 END),0) revenue FROM mc_sales_order WHERE store_id=? AND status NOT IN ('cancelled') AND created_at>=?",
            [$storeId, $from],
        ) ?: [];
        $actual = ['revenue' => (int) ($row['revenue'] ?? 0), 'orders' => (int) ($row['orders'] ?? 0)];
        $items = [];
        foreach (['revenue', 'orders'] as $metric) {
            $target = (int) ($targets[$metric] ?? 0);
            $items[$metric] = [
                'target' => $target,
                'actual' => $actual[$metric],
                'percent' => $target > 0 ? min(100, (int) floor($actual[$metric] / $target * 100)) : 0,
                'projected' => (int) round($actual[$metric] / max(1, $dayOfMonth) * $daysInMonth),
            ];
        }

        return ['month' => $now->format('Y-m'), 'day' => $dayOfMonth, 'days' => $daysInMonth, 'items' => $items];
    }

    /** @param array<string,int> $current @return array<string,int> */
    private function funnel(int $storeId, string $from, string $to, array $current): array
    {
        $quick = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_customer_inquiry WHERE store_id=? AND inquiry_type='quick_order' AND created_at>=? AND created_at<?", [$storeId, $from, $to]);
        $abandoned = (int) $this->db->fetchOne("SELECT COUNT(DISTINCT c.id) FROM mc_cart c JOIN mc_cart_item ci ON ci.cart_id=c.id WHERE c.store_id=? AND c.status='active' AND c.created_at>=? AND c.created_at<? AND c.updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)", [$storeId, $from, $to]);

        return ['carts' => $current['carts'], 'orders' => $current['orders'], 'paid' => $current['paid'], 'abandoned' => $abandoned, 'quick_orders' => $quick];
    }

    /** Orders waiting for the merchant right now, independent of the selected period. @return array<string,int> */
    private function pipeline(int $storeId): array
    {
        $r = $this->db->fetchAssociative(
            "SELECT
                COALESCE(SUM(status IN ('placed','pending','confirmed','processing')),0) to_process,
                COALESCE(SUM(payment_status='pending' AND status NOT IN ('cancelled','completed')),0) awaiting_payment,
                COALESCE(SUM(fulfillment_status IN ('unfulfilled','pending','processing') AND payment_status IN " . self::PAID . " AND status NOT IN ('cancelled','completed')),0) to_ship,
                COALESCE(SUM(status='pending_approval'),0) b2b_approval
             FROM mc_sales_order WHERE store_id=?",
            [$storeId],
        ) ?: [];

        return ['to_process' => (int) ($r['to_process'] ?? 0), 'awaiting_payment' => (int) ($r['awaiting_payment'] ?? 0), 'to_ship' => (int) ($r['to_ship'] ?? 0), 'b2b_approval' => (int) ($r['b2b_approval'] ?? 0)];
    }

    /** @return list<array<string,mixed>> */
    private function topProducts(int $storeId, string $from, string $to): array
    {
        return $this->db->fetchAllAssociative('SELECT oi.sku,oi.name,SUM(oi.quantity) quantity,SUM(oi.line_total_minor) revenue_minor FROM mc_sales_order_item oi JOIN mc_sales_order o ON o.id=oi.order_id WHERE o.store_id=? AND o.created_at>=? AND o.created_at<? AND o.payment_status IN ' . self::PAID . ' GROUP BY oi.sku,oi.name ORDER BY revenue_minor DESC LIMIT 5', [$storeId, $from, $to]);
    }

    /**
     * Reorder hints: available stock divided by the average daily sales of the last 30 days.
     * Only variants that actually sold are listed; unsold stock cannot run out "soon".
     *
     * @return list<array<string,mixed>>
     */
    private function reorder(int $storeId): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $rows = $this->db->fetchAllAssociative(
            "SELECT s.sku,s.name,s.available,s.sold,ROUND(s.sold/30,2) per_day,ROUND(s.available/(s.sold/30),1) cover_days FROM (
                SELECT v.id,v.sku,
                       (SELECT COALESCE(MAX(oi.name),v.sku) FROM mc_sales_order_item oi WHERE oi.variant_id=v.id) name,
                       (SELECT GREATEST(0,COALESCE(SUM(sl.stocked_quantity-sl.reserved_quantity-sl.safety_stock),0)) FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id WHERE vii.variant_id=v.id) available,
                       (SELECT COALESCE(SUM(x.quantity),0) FROM mc_sales_order_item x JOIN mc_sales_order xo ON xo.id=x.order_id WHERE x.variant_id=v.id AND xo.store_id=? AND xo.status NOT IN ('cancelled') AND xo.created_at>=?) sold
                FROM mc_product_variant v
                JOIN mc_store_product sp ON sp.product_id=v.product_id AND sp.store_id=?
             ) s WHERE s.sold>0 AND s.available/(s.sold/30)<14 ORDER BY cover_days ASC LIMIT 6",
            [$storeId, $since, $storeId],
        );

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function sources(int $storeId, string $from, string $to): array
    {
        return $this->db->fetchAllAssociative("SELECT COALESCE(NULLIF(a.last_source,''),'direct') source,COUNT(*) orders,COALESCE(SUM(o.total_minor),0) revenue_minor FROM mc_order_attribution a JOIN mc_sales_order o ON o.id=a.order_id WHERE a.store_id=? AND o.created_at>=? AND o.created_at<? GROUP BY source ORDER BY revenue_minor DESC LIMIT 5", [$storeId, $from, $to]);
    }

    private function offset(\DateTimeZone $tz): string
    {
        $seconds = $tz->getOffset(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return sprintf('%s%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
