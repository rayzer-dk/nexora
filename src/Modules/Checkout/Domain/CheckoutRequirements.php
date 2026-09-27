<?php

declare(strict_types=1);

namespace Commerce\Modules\Checkout\Domain;

final readonly class CheckoutRequirements
{
    public function __construct(
        public bool $requiresFulfillment,
        public bool $requiresName = true,
        public bool $requiresPhone = false,
        public bool $requiresEmail = false,
    ) {
    }

    public static function digitalOnly(): self
    {
        return new self(
            requiresFulfillment: false,
            requiresName: false,
            requiresPhone: false,
            requiresEmail: true,
        );
    }

    public static function physicalDelivery(): self
    {
        return new self(
            requiresFulfillment: true,
            requiresName: true,
            requiresPhone: true,
            requiresEmail: false,
        );
    }
}
