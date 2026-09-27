<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Domain;

final readonly class FulfillmentSnapshot
{
    /** @param array<string,mixed> $providerPayload */
    public function __construct(
        public string $providerCode,
        public string $providerLabel,
        public string $serviceType,
        public string $destinationLabel,
        public bool $verifiedByProvider,
        public array $providerPayload = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toOrderData(): array
    {
        return [
            'provider_code' => $this->providerCode,
            'provider_label' => $this->providerLabel,
            'service_type' => $this->serviceType,
            'destination_label' => $this->destinationLabel,
            'verified_by_provider' => $this->verifiedByProvider,
            'provider_payload' => $this->providerPayload,
        ];
    }
}
