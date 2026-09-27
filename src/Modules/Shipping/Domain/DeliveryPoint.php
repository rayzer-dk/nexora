<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Domain;

final readonly class DeliveryPoint
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public string $id,
        public string $providerCode,
        public string $name,
        public string $city,
        public DeliveryPointType $type,
        public ?string $address = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $postalCode = null,
        public ?string $countryCode = null,
        public array $metadata = [],
    ) {
    }
}
