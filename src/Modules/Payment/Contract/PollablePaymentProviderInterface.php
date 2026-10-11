<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Contract;

/** A provider that can be asked for the state of a payment (instalments confirm in a banking app, so the shopper may return before the bank's callback). */
interface PollablePaymentProviderInterface
{
    /**
     * @return array{status:string,payload:array<string,mixed>}|null the state in the shop's words (success, failure, hold, processing, reversed), null when unknown
     */
    public function pollState(string $providerReference): ?array;
}
