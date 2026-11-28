<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Storefront\Domain\ProductCatalogFilter;
use PHPUnit\Framework\TestCase;

final class ProductCatalogFilterCacheIdentityTest extends TestCase
{
    public function testEquivalentFilterOrdersShareCacheIdentity(): void
    {
        $first = new ProductCatalogFilter(brandIds: [9, 2, 9], attributeFilters: ['size' => ['M', 'S'], 'color' => ['red', 'blue', 'red']]);
        $second = new ProductCatalogFilter(brandIds: [2, 9], attributeFilters: ['color' => ['blue', 'red'], 'size' => ['S', 'M']]);
        self::assertSame($first->cacheBrandIds(), $second->cacheBrandIds());
        self::assertSame($first->cacheAttributeFilters(), $second->cacheAttributeFilters());
        self::assertSame([9, 2, 9], $first->brandIds);
        self::assertNotSame($first->cacheAttributeFilters(), (new ProductCatalogFilter(attributeFilters: ['color' => ['red']]))->cacheAttributeFilters());
    }
}
