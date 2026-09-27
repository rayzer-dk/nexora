<?php

declare(strict_types=1);

namespace Commerce\Modules\Tax\Domain;

use InvalidArgumentException;

final readonly class TaxPriceBreakdown
{
    public function __construct(
        public int $netMinor,
        public int $taxMinor,
        public int $grossMinor,
        public int $rateBps,
        public string $currency,
        public ?string $taxLabel = null,
    ) {
        if ($netMinor < 0 || $taxMinor < 0 || $grossMinor < 0) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.600520193dc8'));
        }
        if ($netMinor + $taxMinor !== $grossMinor) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.65fa9385609c'));
        }
        if ($rateBps < 0 || $rateBps > 10000) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.8d2e0c714b29'));
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.48307ec5e449'));
        }
    }

    public function ratePercent(): string
    {
        $whole = intdiv($this->rateBps, 100);
        $fraction = $this->rateBps % 100;

        return $fraction === 0 ? (string) $whole : sprintf('%d.%02d', $whole, $fraction);
    }
}
