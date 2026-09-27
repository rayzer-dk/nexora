<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Domain;

final readonly class PaymentMethod
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $online,
        public bool $requiresShipping = false,
    ) {}
}
