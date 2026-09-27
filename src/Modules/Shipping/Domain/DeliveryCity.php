<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Domain;

final readonly class DeliveryCity
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public string $id,
        public string $providerCode,
        public string $name,
        public string $countryCode,
        public ?string $region = null,
        public ?string $district = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public array $metadata = [],
    ) {
    }
}
