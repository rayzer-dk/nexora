<?php

declare(strict_types=1);

namespace Commerce\Modules\Tax\Application;

use Commerce\Modules\Tax\Domain\TaxDisplayMode;
use Commerce\Modules\Tax\Domain\TaxPriceBreakdown;

final class TaxDisplayProjection
{
    /**
     * @return array{primary_minor:int,secondary_minor:?int,secondary_kind:?string,tax_minor:int,rate_bps:int,mode:string}
     */
    public function project(TaxPriceBreakdown $price, TaxDisplayMode $mode): array
    {
        return match ($mode) {
            TaxDisplayMode::PriceOnly, TaxDisplayMode::Gross => [
                'primary_minor' => $price->grossMinor,
                'secondary_minor' => null,
                'secondary_kind' => null,
                'tax_minor' => $price->taxMinor,
                'rate_bps' => $price->rateBps,
                'mode' => $mode->value,
            ],
            TaxDisplayMode::GrossWithBreakdown => [
                'primary_minor' => $price->grossMinor,
                'secondary_minor' => $price->taxMinor,
                'secondary_kind' => 'included_tax',
                'tax_minor' => $price->taxMinor,
                'rate_bps' => $price->rateBps,
                'mode' => $mode->value,
            ],
            TaxDisplayMode::NetWithGross => [
                'primary_minor' => $price->netMinor,
                'secondary_minor' => $price->grossMinor,
                'secondary_kind' => 'gross_total',
                'tax_minor' => $price->taxMinor,
                'rate_bps' => $price->rateBps,
                'mode' => $mode->value,
            ],
            TaxDisplayMode::Net => [
                'primary_minor' => $price->netMinor,
                'secondary_minor' => null,
                'secondary_kind' => null,
                'tax_minor' => $price->taxMinor,
                'rate_bps' => $price->rateBps,
                'mode' => $mode->value,
            ],
        };
    }
}
