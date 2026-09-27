<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Application;

use Commerce\Modules\Seo\Contract\SeoUrlRepositoryInterface;
use Commerce\Modules\Seo\Domain\SeoEntityType;
use Commerce\Modules\Seo\Domain\SeoRoute;
use Commerce\Modules\Seo\Domain\SlugMode;
use RuntimeException;

final readonly class SeoUrlManager
{
    public function __construct(
        private SeoUrlRepositoryInterface $repository,
        private SlugGenerator $slugs,
        private SeoPathPolicy $paths,
    ) {
    }

    public function ensureForCreatedEntity(
        int $storeId,
        string $locale,
        SeoEntityType $type,
        string $entityPublicId,
        string $displayName,
        ?string $manualSlug = null,
        SlugMode $mode = SlugMode::TransliterateAscii,
    ): SeoRoute {
        $existing = $this->repository->findByEntity($storeId, $locale, $type, $entityPublicId);
        if ($existing !== null) {
            return $existing;
        }

        $base = $manualSlug === null
            ? $this->slugs->generate($displayName, $locale, $mode)
            : $this->slugs->normalizeManual($manualSlug, $locale, $mode);

        [$slug, $path] = $this->uniquePath($storeId, $locale, $type, $base);
        return $this->repository->create($storeId, $locale, $type, $entityPublicId, $slug, $path);
    }

    public function changeSlug(SeoRoute $route, string $requestedSlug, SlugMode $mode = SlugMode::TransliterateAscii): SeoRoute
    {
        $base = $this->slugs->normalizeManual($requestedSlug, $route->locale, $mode);
        [$slug, $path] = $this->uniquePath($route->storeId, $route->locale, $route->entityType, $base, $route->id);
        return $this->repository->changeCanonical($route, $slug, $path);
    }


    public function preserveLegacyPath(SeoRoute $route, string $legacyPath): void
    {
        $legacyPath = $this->paths->normalizeIncomingPath($legacyPath);
        if ($legacyPath !== '') {
            $this->repository->addRedirectAlias($route, $legacyPath, 'legacy_import');
        }
    }

    /** @return array{0:string,1:string} */
    private function uniquePath(int $storeId, string $locale, SeoEntityType $type, string $baseSlug, ?int $exceptRouteId = null): array
    {
        for ($suffix = 1; $suffix <= 1000; $suffix++) {
            $slug = $this->paths->withCollisionSuffix($baseSlug, $suffix);
            $path = $this->paths->path($type, $slug, $locale);
            if (!$this->repository->pathIsReserved($storeId, $locale, $path, $exceptRouteId)) {
                return [$slug, $path];
            }
        }

        throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f0a5a15817e5'));
    }
}
