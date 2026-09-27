<?php

declare(strict_types=1);

namespace Commerce\Modules\Checkout\Domain;

use InvalidArgumentException;

final readonly class ManualDeliveryDestination implements FulfillmentDestination
{
    public function __construct(
        public string $provider,
        public string $city,
        public string $destination,
        public ?string $serviceType = null,
    ) {
        if (trim($this->provider) === '' || trim($this->destination) === '') {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a6c393a8aca3'));
        }
    }

    public function providerCode(): string
    {
        return $this->provider;
    }

    public function destinationType(): string
    {
        return 'manual_delivery';
    }

    public function normalizedData(): array
    {
        return [
            'provider' => $this->provider,
            'type' => $this->destinationType(),
            'city' => $this->city,
            'destination' => $this->destination,
            'service_type' => $this->serviceType,
            'verification' => 'unverified_manual_fallback',
        ];
    }
}
