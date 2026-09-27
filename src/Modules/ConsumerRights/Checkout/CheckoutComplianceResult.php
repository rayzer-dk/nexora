<?php

declare(strict_types=1);

namespace Commerce\Modules\ConsumerRights\Checkout;

final readonly class CheckoutComplianceResult
{
    /** @param list<string> $violations */
    public function __construct(
        public bool $valid,
        public array $violations = [],
    ) {
    }
}
