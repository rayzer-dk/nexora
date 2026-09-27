<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Domain;

final readonly class OnlinePaymentSession
{
    public function __construct(
        public string $providerReference,
        public string $redirectUrl,
        public ?string $appUrl = null,
        public array $metadata = [],
    ) {}
}
