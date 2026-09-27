<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Application;

final readonly class ShippingCachePolicy
{
    public function __construct(
        public int $cityQueryTtlSeconds = 21600,
        public int $pointQueryTtlSeconds = 600,
        public int $maxPointQueryTtlSeconds = 3600,
        public bool $persistCompleteDirectories = false,
    ) {
    }
}
