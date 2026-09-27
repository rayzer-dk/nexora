<?php

declare(strict_types=1);

namespace Commerce\Modules\ProductPage\Contract;

use Commerce\Modules\ProductPage\Domain\ProductBlockDefinition;

interface ProductBlockProviderInterface
{
    /** @return list<ProductBlockDefinition> */
    public function definitions(): array;
}
