<?php

declare(strict_types=1);

namespace Commerce\Modules\Checkout\Domain;

final readonly class CourierAddressDestination implements FulfillmentDestination
{
    public function __construct(
        public string $provider,
        public string $countryCode,
        public string $city,
        public string $street,
        public string $building,
        public ?string $postalCode = null,
        public ?string $apartment = null,
        public ?string $addressProviderId = null,
    ) {
    }

    public function providerCode(): string
    {
        return $this->provider;
    }

    public function destinationType(): string
    {
        return 'courier_address';
    }

    public function normalizedData(): array
    {
        return [
            'provider' => $this->provider,
            'type' => $this->destinationType(),
            'country_code' => $this->countryCode,
            'city' => $this->city,
            'street' => $this->street,
            'building' => $this->building,
            'postal_code' => $this->postalCode,
            'apartment' => $this->apartment,
            'address_provider_id' => $this->addressProviderId,
        ];
    }
}
