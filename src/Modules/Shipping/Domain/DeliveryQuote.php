<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Domain;

final readonly class DeliveryQuote
{
    public function __construct(
        public string $providerCode,
        public string $serviceCode,
        public string $currency,
        public string $amount,
        public ?int $estimatedMinDays = null,
        public ?int $estimatedMaxDays = null,
    ) {
    }
}
