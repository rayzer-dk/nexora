<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Application;

final readonly class CurrencyRateSnapshot
{
    /** @param array<string,float> $rates */
    public function __construct(
        public string $baseCurrency,
        public array $rates,
        public \DateTimeImmutable $capturedAt,
        public string $provider,
        public bool $stale = false,
    ) {
    }
}
