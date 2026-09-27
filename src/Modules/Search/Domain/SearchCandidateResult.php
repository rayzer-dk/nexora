<?php

declare(strict_types=1);

namespace Commerce\Modules\Search\Domain;

final readonly class SearchCandidateResult
{
    /** @param list<int> $productIds */
    public function __construct(
        public array $productIds,
        public int $estimatedTotal,
        public string $provider,
    ) {
    }
}
