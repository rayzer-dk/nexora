<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Domain;

final readonly class SeoRoute
{
    public function __construct(
        public int $id,
        public int $storeId,
        public string $locale,
        public SeoEntityType $entityType,
        public string $entityPublicId,
        public string $slug,
        public string $path,
        public bool $indexable = true,
    ) {
    }
}
