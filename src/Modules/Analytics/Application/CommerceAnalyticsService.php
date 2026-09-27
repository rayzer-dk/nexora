<?php

declare(strict_types=1);

namespace Commerce\Modules\Analytics\Application;

use Doctrine\DBAL\Connection;

final readonly class CommerceAnalyticsService
{
    public function __construct(private Connection $db) {}

    /** @return array<string,mixed> */
    public function report(int $storeId, int $days): array
    {
        $days = in_array($days, [7,30,90,180,365], true) ? $days : 30;
        $since = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
        $currency = (string)($this->db->fetchOne('SELECT default_currency FROM mc_store WHERE id=?', [$storeId]) ?: 'UAH');

        $order = $this->db->fetchAssociative("SELECT
            COUNT(*) orders_total,
            SUM(payment_status IN ('paid','partially_refunded','refunded')) paid_or_refunded_orders,
            SUM(CASE WHEN payment_status IN ('paid','partially_refunded','refunded') THEN total_minor ELSE 0 END) gross_minor,
            COUNT(DISTINCT customer_id) customers
            FROM mc_sales_order WHERE store_id=? AND created_at>=?", [$storeId,$since]) ?: [];
        $refunds = (int)($this->db->fetchOne("SELECT COALESCE(SUM(r.amount_minor),0) FROM mc_payment_refund r JOIN mc_payment p ON p.id=r.payment_id JOIN mc_sales_order o ON o.id=p.order_id WHERE o.store_id=? AND r.status='succeeded' AND r.created_at>=?",[$storeId,$since]) ?: 0);
        $gross = (int)($order['gross_minor'] ?? 0);
        $net = max(0, $gross - $refunds);
        $paidOrders = (int)($order['paid_or_refunded_orders'] ?? 0);
        $carts = (int)($this->db->fetchOne('SELECT COUNT(*) FROM mc_cart WHERE store_id=? AND created_at>=?',[$storeId,$since]) ?: 0);
        $activeWithItems = (int)($this->db->fetchOne("SELECT COUNT(DISTINCT c.id) FROM mc_cart c JOIN mc_cart_item ci ON ci.cart_id=c.id WHERE c.store_id=? AND c.created_at>=?",[$storeId,$since]) ?: 0);
        $abandoned = (int)($this->db->fetchOne("SELECT COUNT(DISTINCT c.id) FROM mc_cart c JOIN mc_cart_item ci ON ci.cart_id=c.id WHERE c.store_id=? AND c.created_at>=? AND c.status='active' AND c.updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)",[$storeId,$since]) ?: 0);
        $topProducts = $this->db->fetchAllAssociative("SELECT oi.sku,oi.name,SUM(oi.quantity) quantity,SUM(oi.line_total_minor) revenue_minor FROM mc_sales_order_item oi JOIN mc_sales_order o ON o.id=oi.order_id WHERE o.store_id=? AND o.created_at>=? AND o.payment_status IN ('paid','partially_refunded','refunded') GROUP BY oi.sku,oi.name ORDER BY revenue_minor DESC LIMIT 10",[$storeId,$since]);
        $sources = $this->db->fetchAllAssociative("SELECT COALESCE(NULLIF(last_source,''),'direct') source,COALESCE(NULLIF(last_medium,''),'none') medium,COUNT(*) orders,SUM(o.total_minor) revenue_minor FROM mc_order_attribution a JOIN mc_sales_order o ON o.id=a.order_id WHERE a.store_id=? AND o.created_at>=? GROUP BY source,medium ORDER BY revenue_minor DESC LIMIT 10",[$storeId,$since]);
        $searches = $this->db->fetchAllAssociative("SELECT query_text,COUNT(*) searches,ROUND(AVG(result_count),1) avg_results,SUM(result_count=0) zero_results FROM mc_search_query_log WHERE store_id=? AND created_at>=? GROUP BY query_hash,query_text ORDER BY searches DESC LIMIT 20",[$storeId,$since]);
        $zeroSearches = $this->db->fetchAllAssociative("SELECT query_text,COUNT(*) searches FROM mc_search_query_log WHERE store_id=? AND created_at>=? AND result_count=0 GROUP BY query_hash,query_text ORDER BY searches DESC LIMIT 20",[$storeId,$since]);

        return [
            'days'=>$days,'currency'=>$currency,'since'=>$since,
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
}
