<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Domain;

final readonly class ResolvedPrice
{
    public function __construct(
        public Money $sellingPrice,
        public ?Money $compareAtPrice,
        public bool $taxIncluded,
        public string $source,
        public ?string $priceListCode = null,
    ) {
    }
}
