<?php

declare(strict_types=1);

namespace Commerce\Modules\Checkout\Application;

use Doctrine\DBAL\Connection;

/** Weight of a cart in kilograms from the weight of each variant (set in the product), used by the per-kilogram delivery price and the weight limit. */
final readonly class CartWeightCalculator
{
    public function __construct(private Connection $db, private \Commerce\Modules\Catalog\Application\ProductAddonService $addons)
    {
    }

    public function kg(int $cartId): float
    {
        try {
            $kg = 0.0;
            foreach ($this->db->fetchAllAssociative('SELECT v.product_id,v.weight_kg,ci.quantity,ci.metadata FROM mc_cart_item ci JOIN mc_product_variant v ON v.id=ci.variant_id WHERE ci.cart_id=?', [$cartId]) as $row) {
                $each = (float) $row['weight_kg'] + ($row['metadata'] !== null ? $this->addons->weightG((int) $row['product_id'], (string) $row['metadata']) / 1000 : 0.0);
                $kg += $each * (float) $row['quantity'];
            }

            return round($kg, 3);
        } catch (\Throwable) {
            return 0.0;
        }
    }
}
