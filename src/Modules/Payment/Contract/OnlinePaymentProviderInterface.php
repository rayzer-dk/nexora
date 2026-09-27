<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Contract;

use Commerce\Modules\Payment\Domain\OnlinePaymentRequest;
use Commerce\Modules\Payment\Domain\OnlinePaymentSession;
use Commerce\Modules\Payment\Domain\RefundResult;

interface OnlinePaymentProviderInterface extends PaymentProviderInterface
{
    public function createPayment(OnlinePaymentRequest $request): OnlinePaymentSession;

    public function refund(string $providerReference, int $amountMinor, string $idempotencyKey): RefundResult;

    public function cancel(string $providerReference): void;
}
