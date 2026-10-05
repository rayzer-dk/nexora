<?php

declare(strict_types=1);

namespace Commerce\Modules\Analytics\Application;

use Doctrine\DBAL\Connection;

final readonly class CommerceAnalyticsService
{
    public function __construct(private Connection $db) {}

    /** @return array<string,mixed> */
    public function report(int $storeId, int $days, ?string $from = null, ?string $to = null): array
    {
        $days = in_array($days, [7,30,90,180,365], true) ? $days : 30;
        $range = self::range($days, $from, $to);
        $days = $range['days'];
        $since = $range['since'];
        $until = $range['until'];
        $currency = (string)($this->db->fetchOne('SELECT default_currency FROM mc_store WHERE id=?', [$storeId]) ?: 'UAH');

        $order = $this->db->fetchAssociative("SELECT
            COUNT(*) orders_total,
            SUM(payment_status IN ('paid','partially_refunded','refunded')) paid_or_refunded_orders,
            SUM(CASE WHEN payment_status IN ('paid','partially_refunded','refunded') THEN total_minor ELSE 0 END) gross_minor,
            COUNT(DISTINCT customer_id) customers
            FROM mc_sales_order WHERE store_id=? AND created_at>=? AND created_at<?", [$storeId,$since,$until]) ?: [];
        $refunds = (int)($this->db->fetchOne("SELECT COALESCE(SUM(r.amount_minor),0) FROM mc_payment_refund r JOIN mc_payment p ON p.id=r.payment_id JOIN mc_sales_order o ON o.id=p.order_id WHERE o.store_id=? AND r.status='succeeded' AND r.created_at>=? AND r.created_at<?",[$storeId,$since,$until]) ?: 0);
        $gross = (int)($order['gross_minor'] ?? 0);
        $net = max(0, $gross - $refunds);
        $paidOrders = (int)($order['paid_or_refunded_orders'] ?? 0);
        $carts = (int)($this->db->fetchOne('SELECT COUNT(*) FROM mc_cart WHERE store_id=? AND created_at>=? AND created_at<?',[$storeId,$since,$until]) ?: 0);
        $activeWithItems = (int)($this->db->fetchOne("SELECT COUNT(DISTINCT c.id) FROM mc_cart c JOIN mc_cart_item ci ON ci.cart_id=c.id WHERE c.store_id=? AND c.created_at>=? AND c.created_at<?",[$storeId,$since,$until]) ?: 0);
        $abandoned = (int)($this->db->fetchOne("SELECT COUNT(DISTINCT c.id) FROM mc_cart c JOIN mc_cart_item ci ON ci.cart_id=c.id WHERE c.store_id=? AND c.created_at>=? AND c.created_at<? AND c.status='active' AND c.updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)",[$storeId,$since,$until]) ?: 0);
        $topProducts = $this->db->fetchAllAssociative("SELECT oi.sku,oi.name,SUM(oi.quantity) quantity,SUM(oi.line_total_minor) revenue_minor FROM mc_sales_order_item oi JOIN mc_sales_order o ON o.id=oi.order_id WHERE o.store_id=? AND o.created_at>=? AND o.created_at<? AND o.payment_status IN ('paid','partially_refunded','refunded') GROUP BY oi.sku,oi.name ORDER BY revenue_minor DESC LIMIT 10",[$storeId,$since,$until]);
        $sources = $this->db->fetchAllAssociative("SELECT COALESCE(NULLIF(last_source,''),'direct') source,COALESCE(NULLIF(last_medium,''),'none') medium,COUNT(*) orders,SUM(o.total_minor) revenue_minor FROM mc_order_attribution a JOIN mc_sales_order o ON o.id=a.order_id WHERE a.store_id=? AND o.created_at>=? AND o.created_at<? GROUP BY source,medium ORDER BY revenue_minor DESC LIMIT 10",[$storeId,$since,$until]);
        $searches = $this->db->fetchAllAssociative("SELECT query_text,COUNT(*) searches,ROUND(AVG(result_count),1) avg_results,SUM(result_count=0) zero_results FROM mc_search_query_log WHERE store_id=? AND created_at>=? AND created_at<? GROUP BY query_hash,query_text ORDER BY searches DESC LIMIT 20",[$storeId,$since,$until]);
        $zeroSearches = $this->db->fetchAllAssociative("SELECT query_text,COUNT(*) searches FROM mc_search_query_log WHERE store_id=? AND created_at>=? AND created_at<? AND result_count=0 GROUP BY query_hash,query_text ORDER BY searches DESC LIMIT 20",[$storeId,$since,$until]);

        $previous = $this->totals($storeId, gmdate('Y-m-d H:i:s', strtotime($since) - ($days * 86400)), $since);
        $current = $this->totals($storeId, $since, $until);

        return [
            'days'=>$days,'currency'=>$currency,'since'=>$since,'until'=>$until,'from'=>substr($since,0,10),'to'=>substr($until,0,10),
            'compare'=>['orders'=>self::delta($current['orders'],$previous['orders']),'revenue'=>self::delta($current['revenue'],$previous['revenue']),'aov'=>self::delta($current['aov'],$previous['aov']),'previous'=>$previous],
            'daily'=>$this->daily($storeId,$since,$until),
            'by_payment'=>$this->db->fetchAllAssociative("SELECT p.provider_code code,COUNT(*) orders,SUM(o.total_minor) revenue_minor FROM mc_payment p JOIN mc_sales_order o ON o.id=p.order_id WHERE o.store_id=? AND o.created_at>=? AND o.created_at<? GROUP BY p.provider_code ORDER BY orders DESC",[$storeId,$since,$until]),
            'by_delivery'=>$this->db->fetchAllAssociative("SELECT f.provider_code code,COUNT(*) orders,SUM(o.total_minor) revenue_minor FROM mc_fulfillment f JOIN mc_sales_order o ON o.id=f.order_id WHERE o.store_id=? AND o.created_at>=? AND o.created_at<? GROUP BY f.provider_code ORDER BY orders DESC",[$storeId,$since,$until]),
            'by_status'=>$this->db->fetchAllAssociative("SELECT status code,COUNT(*) orders FROM mc_sales_order WHERE store_id=? AND created_at>=? AND created_at<? GROUP BY status ORDER BY orders DESC",[$storeId,$since,$until]),
            'top_customers'=>$this->db->fetchAllAssociative("SELECT MAX(customer_name) name,customer_email_normalized email,COUNT(*) orders,SUM(total_minor) revenue_minor FROM mc_sales_order WHERE store_id=? AND created_at>=? AND created_at<? AND payment_status IN ('paid','partially_refunded','refunded') AND customer_email_normalized IS NOT NULL GROUP BY customer_email_normalized ORDER BY revenue_minor DESC LIMIT 10",[$storeId,$since,$until]),
            'customers_split'=>$this->customersSplit($storeId,$since,$until),
            'metrics'=>[
                'orders'=>(int)($order['orders_total'] ?? 0),
                'paid_orders'=>$paidOrders,
                'gross_minor'=>$gross,
                'refund_minor'=>$refunds,
                'net_minor'=>$net,
                'aov_minor'=>$paidOrders > 0 ? (int)round($net/$paidOrders) : 0,
                'customers'=>(int)($order['customers'] ?? 0),
                'carts'=>$carts,
                'carts_with_items'=>$activeWithItems,
                'abandoned_carts'=>$abandoned,
                'cart_to_order_pct'=>$activeWithItems > 0 ? round(((int)($order['orders_total'] ?? 0) / $activeWithItems)*100,1) : 0.0,
            ],
            'top_products'=>$topProducts,'sources'=>$sources,'searches'=>$searches,'zero_searches'=>$zeroSearches,
        ];
    }

    /** @return array{since:string,until:string,days:int} */
    public static function range(int $days, ?string $from, ?string $to): array
    {
        $f = $from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) === 1 ? strtotime($from . ' 00:00:00 UTC') : false;
        $t = $to !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to) === 1 ? strtotime($to . ' 00:00:00 UTC') : false;
        if ($f !== false && $t !== false && $t >= $f && ($t - $f) <= 730 * 86400) {
            $until = $t + 86400;

            return ['since' => gmdate('Y-m-d H:i:s', $f), 'until' => gmdate('Y-m-d H:i:s', $until), 'days' => max(1, (int) round(($until - $f) / 86400))];
        }

        return ['since' => gmdate('Y-m-d H:i:s', time() - ($days * 86400)), 'until' => gmdate('Y-m-d H:i:s', time() + 60), 'days' => $days];
    }

    /** Percent change against the previous period; null when there is nothing to compare with. */
    private static function delta(int|float $now, int|float $before): ?float
    {
        return $before > 0 ? round((($now - $before) / $before) * 100, 1) : null;
    }

    /** @return array{orders:int,revenue:int,aov:int} paid revenue (before refunds) of a period */
    private function totals(int $storeId, string $since, string $until): array
    {
        $row = $this->db->fetchAssociative("SELECT COUNT(*) orders,SUM(CASE WHEN payment_status IN ('paid','partially_refunded','refunded') THEN 1 ELSE 0 END) paid,SUM(CASE WHEN payment_status IN ('paid','partially_refunded','refunded') THEN total_minor ELSE 0 END) revenue FROM mc_sales_order WHERE store_id=? AND created_at>=? AND created_at<?", [$storeId, $since, $until]) ?: [];
        $paid = (int) ($row['paid'] ?? 0);
        $revenue = (int) ($row['revenue'] ?? 0);

        return ['orders' => (int) ($row['orders'] ?? 0), 'revenue' => $revenue, 'aov' => $paid > 0 ? (int) round($revenue / $paid) : 0];
    }

    /** @return list<array{day:string,orders:int,revenue_minor:int}> one row per day, empty days included */
    public function daily(int $storeId, string $since, string $until): array
    {
        $rows = $this->db->fetchAllAssociative("SELECT DATE(created_at) day,COUNT(*) orders,SUM(CASE WHEN payment_status IN ('paid','partially_refunded','refunded') THEN total_minor ELSE 0 END) revenue_minor FROM mc_sales_order WHERE store_id=? AND created_at>=? AND created_at<? GROUP BY DATE(created_at)", [$storeId, $since, $until]);
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[(string) $r['day']] = ['orders' => (int) $r['orders'], 'revenue_minor' => (int) $r['revenue_minor']];
        }
        $out = [];
        for ($t = strtotime(substr($since, 0, 10) . ' UTC'), $end = strtotime($until . ' UTC'); $t < $end && count($out) < 740; $t += 86400) {
            $d = gmdate('Y-m-d', $t);
            $out[] = ['day' => $d, 'orders' => $byDay[$d]['orders'] ?? 0, 'revenue_minor' => $byDay[$d]['revenue_minor'] ?? 0];
        }

        return $out;
    }

    /** @return array{new:int,returning:int} buyers of the period whose first order is inside it, and those who ordered before */
    private function customersSplit(int $storeId, string $since, string $until): array
    {
        $rows = $this->db->fetchAllAssociative("SELECT o.customer_email_normalized e,(SELECT COUNT(*) FROM mc_sales_order p WHERE p.store_id=o.store_id AND p.customer_email_normalized=o.customer_email_normalized AND p.created_at<?) earlier FROM mc_sales_order o WHERE o.store_id=? AND o.created_at>=? AND o.created_at<? AND o.customer_email_normalized IS NOT NULL GROUP BY o.customer_email_normalized", [$since, $storeId, $since, $until]);
        $new = 0;
        $returning = 0;
        foreach ($rows as $r) {
            ((int) $r['earlier']) > 0 ? ++$returning : ++$new;
        }

        return ['new' => $new, 'returning' => $returning];
    }
}
