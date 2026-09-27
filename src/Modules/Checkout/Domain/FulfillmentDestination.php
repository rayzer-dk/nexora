<?php

declare(strict_types=1);

namespace Commerce\Modules\Checkout\Domain;

interface FulfillmentDestination
{
    public function providerCode(): string;

    public function destinationType(): string;

    public function normalizedData(): array;
}
