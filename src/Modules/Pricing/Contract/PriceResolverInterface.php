<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Contract;

use Commerce\Modules\Pricing\Domain\PriceContext;
use Commerce\Modules\Pricing\Domain\ResolvedPrice;

interface PriceResolverInterface
{
    public function resolveForVariant(int $variantId, PriceContext $context): ?ResolvedPrice;
}
