<?php

declare(strict_types=1);

namespace Commerce\Modules\Checkout\Domain;

final readonly class PickupPointDestination implements FulfillmentDestination
{
    public function __construct(
        public string $provider,
        public string $pointId,
        public string $label,
        public ?string $cityId = null,
        public ?string $cityLabel = null,
    ) {
    }

    public function providerCode(): string
    {
        return $this->provider;
    }

    public function destinationType(): string
    {
        return 'pickup_point';
    }

    public function normalizedData(): array
    {
        return [
            'provider' => $this->provider,
            'type' => $this->destinationType(),
            'point_id' => $this->pointId,
            'label' => $this->label,
            'city_id' => $this->cityId,
            'city_label' => $this->cityLabel,
        ];
    }
}
