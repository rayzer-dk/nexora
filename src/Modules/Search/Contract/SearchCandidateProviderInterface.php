<?php

declare(strict_types=1);

namespace Commerce\Modules\Search\Contract;

use Commerce\Modules\Search\Domain\SearchCandidateResult;
use Commerce\Modules\Storefront\Domain\StorefrontContext;

interface SearchCandidateProviderInterface
{
    /**
     * Returns null when the accelerator is disabled/unavailable. The storefront
     * must then execute its canonical SQL search path without changing behavior.
     */
    public function candidates(StorefrontContext $context, string $query, int $limit = 5000): ?SearchCandidateResult;
}
