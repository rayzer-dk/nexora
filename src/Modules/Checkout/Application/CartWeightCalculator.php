<?php

declare(strict_types=1);

namespace Commerce\Modules\Checkout\Application;

use Doctrine\DBAL\Connection;

/** Weight of a cart in kilograms from the weight of each variant (set in the product), used by the per-kilogram delivery price and the weight limit. */
final readonly class CartWeightCalculator
{
    public function __construct(private Connection $db)
    {
    }

    public function kg(int $cartId): float
    {
        try {
            return round((float) $this->db->fetchOne('SELECT COALESCE(SUM(v.weight_kg*ci.quantity),0) FROM mc_cart_item ci JOIN mc_product_variant v ON v.id=ci.variant_id WHERE ci.cart_id=?', [$cartId]), 3);
        } catch (\Throwable) {
            return 0.0;
        }
    }
}
