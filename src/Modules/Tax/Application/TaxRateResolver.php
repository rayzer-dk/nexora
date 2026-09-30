<?php

declare(strict_types=1);

namespace Commerce\Modules\Tax\Application;

use Doctrine\DBAL\Connection;

/** The VAT rate that applies to a product (its tax class) in a country, and the tax part of a tax-inclusive amount. */
final readonly class TaxRateResolver
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array{rate_bps:int,class_code:?string} */
    public function forProduct(int $productId, string $country): array
    {
        try {
            $row = $this->db->fetchAssociative(
                'SELECT tc.code, (SELECT tx.rate_bps FROM mc_tax_rate tx WHERE tx.tax_class_id=p.tax_class_id AND tx.country_code=? AND tx.enabled=1 AND tx.valid_from<=UTC_TIMESTAMP(6) AND (tx.valid_to IS NULL OR tx.valid_to>UTC_TIMESTAMP(6)) ORDER BY tx.priority ASC, tx.id DESC LIMIT 1) AS rate_bps
                 FROM mc_product p LEFT JOIN mc_tax_class tc ON tc.id=p.tax_class_id WHERE p.id=?',
                [strtoupper($country), $productId],
            );
        } catch (\Throwable) {
            return ['rate_bps' => 0, 'class_code' => null];
        }

        return is_array($row) ? ['rate_bps' => (int) ($row['rate_bps'] ?? 0), 'class_code' => $row['code'] !== null ? (string) $row['code'] : null] : ['rate_bps' => 0, 'class_code' => null];
    }

    /** VAT contained in a gross amount (prices are entered and paid tax-inclusive). */
    public static function includedTax(int $grossMinor, int $rateBps): int
    {
        if ($rateBps <= 0 || $grossMinor <= 0) {
            return 0;
        }

        return $grossMinor - intdiv(($grossMinor * 10000) + intdiv(10000 + $rateBps, 2), 10000 + $rateBps);
    }
}
