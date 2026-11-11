<?php

declare(strict_types=1);

namespace Commerce\Modules\Analytics\Vitals;

use Doctrine\DBAL\Connection;

/**
 * Core Web Vitals from real visitors: LCP and INP in milliseconds, CLS as a unitless score. Samples carry no visitor identifier.
 * Google's thresholds are judged at the 75th percentile, so that is what the report shows.
 */
final readonly class WebVitals
{
    public const PAGE_KINDS = ['home', 'catalog', 'product', 'cart', 'checkout', 'content'];
    public const DEVICES = ['mobile', 'desktop'];
    /** metric => [good up to, poor from, largest value kept] */
    public const LIMITS = ['LCP' => [2500.0, 4000.0, 60000.0], 'INP' => [200.0, 500.0, 60000.0], 'CLS' => [0.1, 0.25, 10.0]];
    private const KEEP_DAYS = 90;
    private const REPORT_ROWS = 20000;

    public function __construct(private Connection $db)
    {
    }

    /** @param array<string,mixed> $metrics metric name => number; unknown or out-of-range values are dropped. */
    public function record(int $storeId, string $pageKind, string $device, array $metrics): int
    {
        if (!in_array($pageKind, self::PAGE_KINDS, true) || !in_array($device, self::DEVICES, true)) {
            return 0;
        }
        $stored = 0;
        foreach (self::LIMITS as $metric => [, , $max]) {
            $value = $metrics[$metric] ?? null;
            if (!is_int($value) && !is_float($value) || $value < 0 || $value > $max) {
                continue;
            }
            $this->db->insert('mc_web_vital', ['store_id' => $storeId, 'page_kind' => $pageKind, 'device' => $device, 'metric' => $metric, 'value' => round((float) $value, 3), 'created_at' => gmdate('Y-m-d H:i:s')]);
            ++$stored;
        }
        if (random_int(1, 200) === 1) {
            $this->db->executeStatement('DELETE FROM mc_web_vital WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400)]);
        }

        return $stored;
    }

    /**
     * @return list<array{page_kind:string,device:string,metric:string,samples:int,p75:float,rating:string}>
     */
    public function report(int $storeId, int $days): array
    {
        $days = max(1, min(self::KEEP_DAYS, $days));
        $rows = $this->db->fetchAllAssociative(
            'SELECT page_kind,device,metric,value FROM mc_web_vital WHERE store_id=? AND created_at>=? ORDER BY id DESC LIMIT ' . self::REPORT_ROWS,
            [$storeId, gmdate('Y-m-d H:i:s', time() - $days * 86400)],
        );
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['page_kind'] . '|' . $row['device'] . '|' . $row['metric']][] = (float) $row['value'];
        }
        $out = [];
        foreach ($groups as $key => $values) {
            [$pageKind, $device, $metric] = explode('|', $key);
            $p75 = self::percentile($values, 0.75);
            [$good, $poor] = self::LIMITS[$metric];
            $out[] = ['page_kind' => $pageKind, 'device' => $device, 'metric' => $metric, 'samples' => count($values), 'p75' => $p75, 'rating' => $p75 <= $good ? 'good' : ($p75 >= $poor ? 'poor' : 'needs-improvement')];
        }
        usort($out, static fn (array $a, array $b): int => [$a['page_kind'], $a['device'], $a['metric']] <=> [$b['page_kind'], $b['device'], $b['metric']]);

        return $out;
    }

    /** @param non-empty-list<float> $values nearest-rank percentile */
    public static function percentile(array $values, float $p): float
    {
        sort($values);

        return $values[max(0, (int) ceil($p * count($values)) - 1)];
    }
}
