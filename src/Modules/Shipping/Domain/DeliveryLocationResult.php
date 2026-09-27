<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Domain;

final readonly class DeliveryLocationResult
{
    /**
     * @param list<DeliveryPoint> $points
     */
    public function __construct(
        public string $providerCode,
        public array $points,
        public ProviderAvailability $availability,
        public bool $manualFallbackAllowed,
        public ?string $notice = null,
    ) {
    }
}
