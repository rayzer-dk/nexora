<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Contract;

use Commerce\Modules\Seo\Domain\SeoEntityType;
use Commerce\Modules\Seo\Domain\SeoRoute;
use Commerce\Modules\Seo\Domain\SeoRouteResolution;

interface SeoUrlRepositoryInterface
{
    public function pathIsReserved(int $storeId, string $locale, string $path, ?int $exceptRouteId = null): bool;

    public function findByEntity(int $storeId, string $locale, SeoEntityType $entityType, string $entityPublicId): ?SeoRoute;

    public function create(int $storeId, string $locale, SeoEntityType $entityType, string $entityPublicId, string $slug, string $path): SeoRoute;

    public function changeCanonical(SeoRoute $route, string $slug, string $path): SeoRoute;

    public function addRedirectAlias(SeoRoute $route, string $sourcePath, string $reason = 'legacy_import'): void;

    /** Switches the page between index and noindex (robots meta, sitemap, feeds and IndexNow follow the flag). */
    public function setIndexable(int $storeId, string $locale, SeoEntityType $entityType, string $entityPublicId, bool $indexable): void;

    public function resolve(int $storeId, string $locale, string $path): SeoRouteResolution;
}
