<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Domain;

final readonly class RefundResult
{
    public function __construct(
        public string $status,
        public array $providerPayload = [],
    ) {}
}
