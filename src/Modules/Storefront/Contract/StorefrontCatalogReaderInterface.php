<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Contract;

use Commerce\Modules\Storefront\Domain\ProductCatalogFilter;
use Commerce\Modules\Storefront\Domain\StorefrontContext;

interface StorefrontCatalogReaderInterface
{
    /** @return array<string,mixed>|null */
    public function purchasableVariant(StorefrontContext $context, string $variantPublicId): ?array;

    /** @return list<array<string,mixed>> */
    public function topCategories(StorefrontContext $context, int $limit = 24, string $order = 'manual'): array;

    /** @return array<string,mixed> */
    public function products(
        StorefrontContext $context,
        ?int $categoryId = null,
        int $page = 1,
        int $limit = 24,
        ?string $search = null,
        ?ProductCatalogFilter $filter = null,
    ): array;
}
