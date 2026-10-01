<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Contract;

use Commerce\Modules\Pricing\Domain\ReferenceRateTable;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One official exchange-rate publisher (central bank). Every source returns a table relative to its own
 * pivot currency (EUR for the ECB, UAH for the NBU, PLN for the NBP, CZK for the CNB); the exchange rate
 * service derives cross rates between any two listed currencies from it.
 */
#[AutoconfigureTag('commerce.rate_source')]
interface ReferenceRateSourceInterface
{
    /** Short stable code stored in mc_store_currency.rate_source and mc_exchange_rate.provider. */
    public function code(): string;

    /** @throws \Throwable when the publisher is unreachable or returns something unusable */
    public function table(): ReferenceRateTable;
}
