<?php

declare(strict_types=1);

namespace Commerce\Modules\Content\Contract;

interface LatestArticlesInterface
{
    /** @return list<array<string,mixed>> */
    public function latest(int $storeId, string $locale, int $limit = 12): array;
}
