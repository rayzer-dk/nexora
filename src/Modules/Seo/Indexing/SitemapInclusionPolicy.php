<?php

declare(strict_types=1);
namespace Commerce\Modules\Seo\Indexing;

final class SitemapInclusionPolicy
{
    public function include(bool $indexable, bool $canonical, int $page = 1, bool $isRuntimeFacet = false): bool
    {
        return $indexable && $canonical && $page === 1 && !$isRuntimeFacet;
    }
}
