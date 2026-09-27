<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Domain;

final readonly class OnlinePaymentRequest
{
    public function __construct(
        public string $orderPublicId,
        public string $orderNumber,
        public int $amountMinor,
        public string $currency,
        public string $returnUrl,
        public string $webhookUrl,
    ) {}
}
