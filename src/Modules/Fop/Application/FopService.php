<?php

declare(strict_types=1);

namespace Commerce\Modules\Fop\Application;

use Doctrine\DBAL\Connection;

/**
 * Income of a shop run by a sole proprietor (FOP, single-tax payer): what has been received this year against the limit of the group.
 * Income is the money received (paid payments by the date of payment), less refunds; it is a working tool, not tax advice: the accountant
 * decides what counts, and the limit is set from the minimum wage (editable) because it changes every year.
 */
final readonly class FopService
{
    /** Limit of the year in minimum wages of 1 January, by group of the single tax. */
    public const WAGES = [1 => 167, 2 => 834, 3 => 1167];

    public function __construct(private Connection $db)
    {
    }

    /** @return array{enabled:bool,tax_group:int,min_wage_minor:int,limit_override_minor:?int,warn_percent:int} */
    public function settings(int $storeId): array
    {
        $row = $this->db->fetchAssociative('SELECT enabled,tax_group,min_wage_minor,limit_override_minor,warn_percent FROM mc_fop_settings WHERE store_id=?', [$storeId]);
        if (!is_array($row)) {
            return ['enabled' => false, 'tax_group' => 3, 'min_wage_minor' => 864700, 'limit_override_minor' => null, 'warn_percent' => 80];
        }

        return [
            'enabled' => (bool) $row['enabled'], 'tax_group' => (int) $row['tax_group'], 'min_wage_minor' => (int) $row['min_wage_minor'],
            'limit_override_minor' => $row['limit_override_minor'] !== null ? (int) $row['limit_override_minor'] : null, 'warn_percent' => (int) $row['warn_percent'],
        ];
    }

    public function save(int $storeId, bool $enabled, int $group, string $minWage, string $limitOverride, int $warnPercent): void
    {
        $toMinor = static function (string $v): ?int {
            $v = str_replace([' ', ','], ['', '.'], trim($v));
            if ($v === '') {
                return null;
            }
            if (!is_numeric($v) || (float) $v < 0 || (float) $v > 9.0e12) {
                throw new \DomainException('number');
            }

            return (int) round((float) $v * 100);
        };
        $wage = $toMinor($minWage) ?? 864700;
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->executeStatement(
            'INSERT INTO mc_fop_settings (store_id,enabled,tax_group,min_wage_minor,limit_override_minor,warn_percent,updated_at) VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),tax_group=VALUES(tax_group),min_wage_minor=VALUES(min_wage_minor),limit_override_minor=VALUES(limit_override_minor),warn_percent=VALUES(warn_percent),updated_at=VALUES(updated_at)',
            [$storeId, $enabled ? 1 : 0, max(1, min(3, $group)), $wage, $toMinor($limitOverride), max(10, min(100, $warnPercent)), $now],
        );
    }

    /** @param array{tax_group:int,min_wage_minor:int,limit_override_minor:?int} $settings */
    public static function limitMinor(array $settings): int
    {
        return $settings['limit_override_minor'] ?? self::WAGES[$settings['tax_group']] * $settings['min_wage_minor'];
    }

    /** @return array<int,int> month number => money received, in minor units */
    public function incomeByMonth(int $storeId, int $year): array
    {
        $months = array_fill(1, 12, 0);
        $rows = $this->db->fetchAllAssociative(
            "SELECT MONTH(p.paid_at) AS m, SUM(p.amount_minor-p.refunded_minor) AS total FROM mc_payment p JOIN mc_sales_order o ON o.id=p.order_id
             WHERE o.store_id=? AND p.paid_at IS NOT NULL AND p.status IN ('paid','partially_refunded','refunded') AND p.paid_at>=? AND p.paid_at<? GROUP BY MONTH(p.paid_at)",
            [$storeId, $year . '-01-01 00:00:00', ($year + 1) . '-01-01 00:00:00'],
        );
        foreach ($rows as $r) {
            $months[(int) $r['m']] = max(0, (int) $r['total']);
        }

        return $months;
    }

    /**
     * @return array{year:int,income:int,limit:int,percent:float,remaining:int,status:string,forecast:?int,months:array<int,int>,quarters:array<int,int>,currency:string}
     */
    public function summary(int $storeId, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $year = (int) $now->format('Y');
        $settings = $this->settings($storeId);
        $months = $this->incomeByMonth($storeId, $year);
        $income = array_sum($months);
        $limit = self::limitMinor($settings);
        $day = (int) $now->format('z') + 1;
        $days = (int) $now->format('L') === 1 ? 366 : 365;
        $forecast = $day >= 30 ? (int) round($income / $day * $days) : null;
        $percent = $limit > 0 ? round($income / $limit * 100, 1) : 0.0;
        $status = match (true) {
            $limit > 0 && $income >= $limit => 'over',
            $limit > 0 && $percent >= $settings['warn_percent'] => 'warn',
            $limit > 0 && $forecast !== null && $forecast > $limit => 'forecast',
            default => 'ok',
        };
        $quarters = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        foreach ($months as $m => $v) {
            $quarters[(int) ceil($m / 3)] += $v;
        }
        $currency = (string) ($this->db->fetchOne('SELECT default_currency FROM mc_store WHERE id=?', [$storeId]) ?: 'UAH');

        return ['year' => $year, 'income' => $income, 'limit' => $limit, 'percent' => $percent, 'remaining' => max(0, $limit - $income), 'status' => $status, 'forecast' => $forecast, 'months' => $months, 'quarters' => $quarters, 'currency' => $currency];
    }

    /** The warning for the dashboard: null when the module is off or everything is in order. @return array{status:string,percent:float}|null */
    public function alert(int $storeId): ?array
    {
        if (!$this->settings($storeId)['enabled']) {
            return null;
        }
        $s = $this->summary($storeId);

        return $s['status'] === 'ok' ? null : ['status' => $s['status'], 'percent' => $s['percent']];
    }

    /**
     * The book of income of the period: one line per payment received, as an accountant enters it.
     *
     * @return list<array{date:string,document:string,method:string,amount_minor:int,note:string}>
     */
    public function incomeBook(int $storeId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT p.paid_at,o.order_number,p.provider_code,p.amount_minor,p.refunded_minor FROM mc_payment p JOIN mc_sales_order o ON o.id=p.order_id
             WHERE o.store_id=? AND p.paid_at IS NOT NULL AND p.status IN ('paid','partially_refunded','refunded') AND p.paid_at>=? AND p.paid_at<? ORDER BY p.paid_at,p.id",
            [$storeId, $from->format('Y-m-d 00:00:00'), $to->modify('+1 day')->format('Y-m-d 00:00:00')],
        );
        $out = [];
        foreach ($rows as $r) {
            $net = (int) $r['amount_minor'] - (int) $r['refunded_minor'];
            if ($net <= 0) {
                continue;
            }
            $out[] = ['date' => substr((string) $r['paid_at'], 0, 10), 'document' => (string) $r['order_number'], 'method' => (string) $r['provider_code'], 'amount_minor' => $net, 'note' => (int) $r['refunded_minor'] > 0 ? 'refund deducted' : ''];
        }

        return $out;
    }
}
