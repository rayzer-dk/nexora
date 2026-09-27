<?php

declare(strict_types=1);

namespace Commerce\Modules\Tax\Application;

use Commerce\Modules\Tax\Domain\TaxPriceBreakdown;
use InvalidArgumentException;

final class TaxPriceCalculator
{
    public function fromGross(int $grossMinor, int $rateBps, string $currency, ?string $taxLabel = null): TaxPriceBreakdown
    {
        $this->assertInput($grossMinor, $rateBps);

        if ($rateBps === 0) {
            return new TaxPriceBreakdown($grossMinor, 0, $grossMinor, 0, $currency, $taxLabel);
        }

        $netMinor = intdiv(($grossMinor * 10000) + intdiv(10000 + $rateBps, 2), 10000 + $rateBps);
        $taxMinor = $grossMinor - $netMinor;

        return new TaxPriceBreakdown($netMinor, $taxMinor, $grossMinor, $rateBps, $currency, $taxLabel);
    }

    public function fromNet(int $netMinor, int $rateBps, string $currency, ?string $taxLabel = null): TaxPriceBreakdown
    {
        $this->assertInput($netMinor, $rateBps);

        $taxMinor = intdiv(($netMinor * $rateBps) + 5000, 10000);
        $grossMinor = $netMinor + $taxMinor;

        return new TaxPriceBreakdown($netMinor, $taxMinor, $grossMinor, $rateBps, $currency, $taxLabel);
    }

    private function assertInput(int $amountMinor, int $rateBps): void
    {
        if ($amountMinor < 0) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.224632be4749'));
        }
        if ($rateBps < 0 || $rateBps > 10000) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.12c2e8b7725c'));
        }
    }
}
