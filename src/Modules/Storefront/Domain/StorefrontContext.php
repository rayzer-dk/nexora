<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Domain;

final readonly class StorefrontContext
{
    public function __construct(
        public int $storeId,
        public int $marketId,
        public string $locale,
        public string $currency,
        public string $countryCode,
        public string $storeName,
        public string $customerGroup = 'default',
        public int $groupDiscountBps = 0,
        public bool $groupSkipsSale = false,
    ) {
    }
}
