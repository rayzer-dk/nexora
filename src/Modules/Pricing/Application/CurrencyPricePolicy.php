<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Application;

final class CurrencyPricePolicy
{
    /**
     * Explicit market/store price always wins. Automatic conversion is a fallback only.
     * @param array<string, int> $explicitMinorByCurrency
     */
    public function selectExplicitPrice(array $explicitMinorByCurrency, string $requestedCurrency): ?int
    {
        $requestedCurrency = strtoupper($requestedCurrency);
        return array_key_exists($requestedCurrency, $explicitMinorByCurrency)
            ? (int) $explicitMinorByCurrency[$requestedCurrency]
            : null;
    }
}
