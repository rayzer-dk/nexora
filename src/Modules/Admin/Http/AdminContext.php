<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

final readonly class AdminContext
{
    public function __construct(
        public int $storeId,
        public int $marketId,
        public string $locale,
        public string $currency,
    ) {
    }
}
