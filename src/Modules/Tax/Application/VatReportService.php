<?php

declare(strict_types=1);

namespace Commerce\Modules\Tax\Application;

use Doctrine\DBAL\Connection;

/**
 * VAT collected by the country of the buyer (the base of a One-Stop-Shop return): per quarter and country, how many orders, what they cost
 * and how much of it is VAT. Cancelled and unpaid-and-expired orders are left out; refunds are shown apart so the accountant can net them.
 */
final readonly class VatReportService
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array{from:\DateTimeImmutable,to:\DateTimeImmutable} the first day of the quarter and the first day after it */
    public static function quarter(int $year, int $quarter): array
    {
        $quarter = max(1, min(4, $quarter));
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, ($quarter - 1) * 3 + 1), new \DateTimeZone('UTC'));

        return ['from' => $from, 'to' => $from->modify('+3 months')];
    }

    /** @return list<array{country:string,orders:int,gross_minor:int,vat_minor:int,net_minor:int,refunded_minor:int,currency:string}> */
    public function byCountry(int $storeId, int $year, int $quarter): array
    {
        $range = self::quarter($year, $quarter);
        $rows = $this->db->fetchAllAssociative(
            "SELECT o.tax_country_code AS country,o.currency,COUNT(*) AS orders,SUM(o.total_minor) AS gross,SUM(o.tax_minor) AS vat,
                    COALESCE(SUM((SELECT COALESCE(SUM(p.refunded_minor),0) FROM mc_payment p WHERE p.order_id=o.id)),0) AS refunded
             FROM mc_sales_order o WHERE o.store_id=? AND o.created_at>=? AND o.created_at<? AND o.status NOT IN ('cancelled') AND o.tax_country_code IS NOT NULL AND o.tax_country_code<>''
             GROUP BY o.tax_country_code,o.currency ORDER BY o.tax_country_code,o.currency",
            [$storeId, $range['from']->format('Y-m-d H:i:s'), $range['to']->format('Y-m-d H:i:s')],
        );

        return array_map(static fn (array $r): array => [
            'country' => (string) $r['country'], 'orders' => (int) $r['orders'], 'gross_minor' => (int) $r['gross'], 'vat_minor' => (int) $r['vat'],
            'net_minor' => (int) $r['gross'] - (int) $r['vat'], 'refunded_minor' => (int) $r['refunded'], 'currency' => (string) $r['currency'],
        ], $rows);
    }
}
