<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Contract;

interface CurrencyRateProviderInterface
{
    public function code(): string;

    /** @return array<string,float> rates relative to base currency */
    public function rates(string $baseCurrency, array $targetCurrencies): array;
}
