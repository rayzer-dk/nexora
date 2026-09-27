<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Domain;

final readonly class DeliveryQuoteRequest
{
    /**
     * @param array<string,mixed> $cart
     * @param array<string,mixed> $destination
     */
    public function __construct(
        public array $cart,
        public array $destination,
        public ?string $serviceCode = null,
    ) {
    }
}
