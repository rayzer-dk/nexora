<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Application;

use Commerce\Modules\Pricing\Contract\CurrencyRateProviderInterface;
use RuntimeException;
use Throwable;

final class ResilientCurrencyRateResolver
{
    /** @param iterable<CurrencyRateProviderInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
    }

    /**
     * @param list<string> $targets
     * @param array<string,float> $lastKnownGood
     */
    public function resolve(string $baseCurrency, array $targets, array $lastKnownGood = []): CurrencyRateSnapshot
    {
        $baseCurrency = strtoupper($baseCurrency);
        $targets = array_values(array_unique(array_map('strtoupper', $targets)));
        foreach ($this->providers as $provider) {
            try {
                $rates = $provider->rates($baseCurrency, $targets);
                $valid = $this->validate($targets, $rates);
                if ($valid !== []) {
                    return new CurrencyRateSnapshot($baseCurrency, $valid, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $provider->code(), false);
                }
            } catch (Throwable) {
                // Provider failure is isolated. Try next provider or last known good rates.
            }
        }
        $fallback = $this->validate($targets, $lastKnownGood);
        if ($fallback !== []) {
            return new CurrencyRateSnapshot($baseCurrency, $fallback, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 'last_known_good', true);
        }
        throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ebc44bbd616e'));
    }

    /** @param list<string> $targets @param array<string,float> $rates @return array<string,float> */
    private function validate(array $targets, array $rates): array
    {
        $out = [];
        foreach ($targets as $currency) {
            $rate = $rates[$currency] ?? null;
            if (is_int($rate) || is_float($rate)) {
                $rate = (float) $rate;
                if (is_finite($rate) && $rate > 0.00000001 && $rate < 100000000.0) {
                    $out[$currency] = $rate;
                }
            }
        }
        return $out;
    }
}
