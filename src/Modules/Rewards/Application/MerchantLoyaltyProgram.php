<?php

declare(strict_types=1);

namespace Commerce\Modules\Rewards\Application;

use Doctrine\DBAL\Connection;

/**
 * The store's points programme as Google Merchant Center sees it: the programme label created there plus the points a
 * buyer earns for a product. Nothing is exported until the store has the points programme on and a label set.
 */
final readonly class MerchantLoyaltyProgram
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array{label:string,points_per_major:int}|null */
    public function forStore(int $storeId): ?array
    {
        $row = $this->db->fetchAssociative(
            "SELECT t.loyalty_program_label label,c.earn_points_per_major rate FROM mc_loyalty_config c JOIN mc_tracking_settings t ON t.store_id=c.store_id WHERE c.store_id=? AND c.enabled=1 AND t.loyalty_program_label<>''",
            [$storeId],
        );

        return is_array($row) && (int) $row['rate'] > 0 ? ['label' => (string) $row['label'], 'points_per_major' => (int) $row['rate']] : null;
    }

    /** Same rule as the points credited for a paid order: whole major units of the price times the rate, scaled by the product's own percent. */
    public static function points(int $priceMinor, int $pointsPerMajor, ?int $productPercent): int
    {
        return (int) round(intdiv(max(0, $priceMinor), 100) * $pointsPerMajor * ($productPercent ?? 100) / 100);
    }
}
