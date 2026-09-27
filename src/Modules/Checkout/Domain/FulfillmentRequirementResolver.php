<?php

declare(strict_types=1);

namespace Commerce\Modules\Checkout\Domain;

use Commerce\Modules\Catalog\Domain\ProductFulfillmentType;

final class FulfillmentRequirementResolver
{
    /**
     * @param iterable<ProductFulfillmentType> $itemTypes
     */
    public function resolve(iterable $itemTypes): CheckoutRequirements
    {
        $hasPhysical = false;
        $hasDigital = false;

        foreach ($itemTypes as $type) {
            if ($type === ProductFulfillmentType::Physical) {
                $hasPhysical = true;
            }
            if ($type === ProductFulfillmentType::Digital) {
                $hasDigital = true;
            }
        }

        if ($hasPhysical) {
            return new CheckoutRequirements(
                requiresFulfillment: true,
                requiresName: true,
                requiresPhone: true,
                requiresEmail: $hasDigital,
            );
        }

        if ($hasDigital) {
            return CheckoutRequirements::digitalOnly();
        }

        return new CheckoutRequirements(
            requiresFulfillment: false,
            requiresName: false,
            requiresPhone: false,
            requiresEmail: false,
        );
    }
}
