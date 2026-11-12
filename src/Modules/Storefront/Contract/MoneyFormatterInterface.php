<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Contract;

interface MoneyFormatterInterface
{
    public function format(int $minor, string $currency, string $locale): string;
}
