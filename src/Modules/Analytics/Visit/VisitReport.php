<?php

declare(strict_types=1);

namespace Commerce\Modules\Analytics\Visit;

use Doctrine\DBAL\Connection;

/** Read model for the traffic page and the dashboard funnel. */
final class VisitReport
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string,mixed> */
    public function build(int $storeId, int $days): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $tzName = (string) ($this->db->fetchOne('SELECT timezone FROM mc_store WHERE id=?', [$storeId]) ?: 'UTC');
        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
        }
        $utc = new \DateTimeZone('UTC');
        $today = new \DateTimeImmutable('today', $tz);
        $from = $today->modify('-' . ($days - 1) . ' days');
        $since = $from->setTimezone($utc)->format('Y-m-d H:i:s');
        $until = $today->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
        $prevSince = $from->modify('-' . $days . ' days')->setTimezone($utc)->format('Y-m-d H:i:s');
        $seconds = $tz->getOffset(new \DateTimeImmutable('now', $utc));
        $offset = sprintf('%s%02d:%02d', $seconds < 0 ? '-' : '+', intdiv(abs($seconds), 3600), intdiv(abs($seconds) % 3600, 60));

        $totals = $this->totals($storeId, $since, $until);
        $previous = $this->totals($storeId, $prevSince, $since);

        $rows = $this->db->fetchAllAssociative(
            'SELECT DATE(CONVERT_TZ(started_at,\'+00:00\',?)) d,COUNT(*) sessions,COALESCE(SUM(pageviews),0) pageviews FROM mc_analytics_session WHERE store_id=? AND started_at>=? AND started_at<? GROUP BY d',
            [$offset, $storeId, $since, $until],
        );
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[(string) $r['d']] = ['sessions' => (int) $r['sessions'], 'pageviews' => (int) $r['pageviews']];
        }
        $series = [];
        $max = 0;
        for ($i = 0; $i < $days; $i++) {
            $d = $from->modify('+' . $i . ' days')->format('Y-m-d');
            $v = $byDay[$d] ?? ['sessions' => 0, 'pageviews' => 0];
            $max = max($max, $v['sessions']);
            $series[] = ['day' => $d, 'sessions' => $v['sessions'], 'pageviews' => $v['pageviews']];
        }
        foreach ($series as &$point) {
            $point['height'] = $max > 0 ? max($point['sessions'] > 0 ? 3 : 0, (int) round($point['sessions'] / $max * 100)) : 0;
        }
        unset($point);

        $sessions = max(1, $totals['sessions']);
        $funnel = [
            ['key' => 'sessions', 'count' => $totals['sessions'], 'percent' => 100],
            ['key' => 'product', 'count' => $totals['viewed_product'], 'percent' => (int) round($totals['viewed_product'] / $sessions * 100)],
            ['key' => 'cart', 'count' => $totals['added_to_cart'], 'percent' => (int) round($totals['added_to_cart'] / $sessions * 100)],
            ['key' => 'checkout', 'count' => $totals['started_checkout'], 'percent' => (int) round($totals['started_checkout'] / $sessions * 100)],
            ['key' => 'order', 'count' => $totals['ordered'], 'percent' => round($totals['ordered'] / $sessions * 100, 1)],
        ];

        $dayFrom = $since;
        return [
            'days' => $days,
            'timezone' => $tz->getName(),
            'totals' => $totals + [
                'pages_per_session' => $totals['sessions'] > 0 ? round($totals['pageviews'] / $totals['sessions'], 1) : 0.0,
                'bounce_rate' => $totals['sessions'] > 0 ? (int) round($totals['bounced'] / $totals['sessions'] * 100) : 0,
                'conversion' => $totals['sessions'] > 0 ? round($totals['ordered'] / $totals['sessions'] * 100, 2) : 0.0,
            ],
            'delta' => [
                'sessions' => $this->delta($totals['sessions'], $previous['sessions']),
                'pageviews' => $this->delta($totals['pageviews'], $previous['pageviews']),
                'visitors' => $this->delta($totals['visitors'], $previous['visitors']),
                'ordered' => $this->delta($totals['ordered'], $previous['ordered']),
            ],
            'series' => $series,
            'funnel' => $funnel,
            'sources' => $this->db->fetchAllAssociative('SELECT source,medium,COUNT(*) sessions,SUM(ordered) orders FROM mc_analytics_session WHERE store_id=? AND started_at>=? AND started_at<? GROUP BY source,medium ORDER BY sessions DESC LIMIT 10', [$storeId, $since, $until]),
            'devices' => $this->db->fetchAllAssociative('SELECT device,COUNT(*) sessions FROM mc_analytics_session WHERE store_id=? AND started_at>=? AND started_at<? GROUP BY device ORDER BY sessions DESC', [$storeId, $since, $until]),
            'campaigns' => $this->db->fetchAllAssociative("SELECT campaign,source,COUNT(*) sessions,SUM(ordered) orders FROM mc_analytics_session WHERE store_id=? AND started_at>=? AND started_at<? AND campaign<>'' GROUP BY campaign,source ORDER BY sessions DESC LIMIT 10", [$storeId, $since, $until]),
            'top_pages' => $this->db->fetchAllAssociative('SELECT path,SUM(views) views,SUM(entrances) entrances FROM mc_analytics_page_daily WHERE store_id=? AND day>=? AND day<=? GROUP BY path ORDER BY views DESC LIMIT 15', [$storeId, substr($dayFrom, 0, 10), gmdate('Y-m-d')]),
            'landing' => $this->db->fetchAllAssociative('SELECT landing_path path,COUNT(*) sessions,SUM(pageviews=1) bounced,SUM(ordered) orders FROM mc_analytics_session WHERE store_id=? AND started_at>=? AND started_at<? GROUP BY landing_path ORDER BY sessions DESC LIMIT 10', [$storeId, $since, $until]),
            'has_data' => $totals['sessions'] > 0,
        ];
    }

    /** @return array{sessions:int,visitors:int,pageviews:int,bounced:int,viewed_product:int,added_to_cart:int,started_checkout:int,ordered:int} */
    public function totals(int $storeId, string $since, string $until): array
    {
        $r = $this->db->fetchAssociative(
            'SELECT COUNT(*) sessions,COUNT(DISTINCT visitor_hash) visitors,COALESCE(SUM(pageviews),0) pageviews,COALESCE(SUM(pageviews=1),0) bounced,COALESCE(SUM(viewed_product),0) viewed_product,COALESCE(SUM(added_to_cart),0) added_to_cart,COALESCE(SUM(started_checkout),0) started_checkout,COALESCE(SUM(ordered),0) ordered FROM mc_analytics_session WHERE store_id=? AND started_at>=? AND started_at<?',
            [$storeId, $since, $until],
        ) ?: [];

        return array_map('intval', $r + ['sessions' => 0, 'visitors' => 0, 'pageviews' => 0, 'bounced' => 0, 'viewed_product' => 0, 'added_to_cart' => 0, 'started_checkout' => 0, 'ordered' => 0]);
    }

    private function delta(int $now, int $before): ?int
    {
        return $before > 0 ? (int) round(($now - $before) / $before * 100) : null;
    }
}
