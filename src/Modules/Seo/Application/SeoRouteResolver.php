<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Application;

use Commerce\Modules\Seo\Contract\SeoUrlRepositoryInterface;
use Commerce\Modules\Seo\Domain\SeoRouteResolution;

final readonly class SeoRouteResolver
{
    public function __construct(private SeoUrlRepositoryInterface $repository, private SeoPathPolicy $paths)
    {
    }

    public function resolve(int $storeId, string $locale, string $requestPath): SeoRouteResolution
    {
        return $this->repository->resolve($storeId, $locale, $this->paths->normalizeIncomingPath($requestPath));
    }
}
