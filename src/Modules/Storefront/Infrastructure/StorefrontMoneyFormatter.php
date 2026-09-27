<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Infrastructure;

use NumberFormatter;

final class StorefrontMoneyFormatter
{
    public function format(int $minor, string $currency, string $locale): string
    {
        $formatter = new NumberFormatter(str_replace('-', '_', $locale), NumberFormatter::CURRENCY);
        $formatted = $formatter->formatCurrency($minor / 100, $currency);
        if ($formatted !== false) {
            return $formatted;
        }

        return number_format($minor / 100, 2, '.', ' ') . ' ' . $currency;
    }
}
