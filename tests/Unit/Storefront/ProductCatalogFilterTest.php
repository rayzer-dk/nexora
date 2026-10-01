<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit\Storefront;

use Commerce\Modules\Catalog\Application\Command\UpdateProductCommand;
use Commerce\Modules\Storefront\Domain\ProductCatalogFilter;
use PHPUnit\Framework\TestCase;

final class ProductCatalogFilterTest extends TestCase
{
    public function testMinimumRatingMakesTheFilterActive(): void
    {
        self::assertFalse((new ProductCatalogFilter())->isFiltered());
        $filter = new ProductCatalogFilter(minRating: 4);
        self::assertTrue($filter->isFiltered());
        self::assertTrue($filter->isFilteredExceptSearch());
    }

    public function testRatingOutsideZeroToFiveIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProductCatalogFilter(minRating: 6);
    }

    public function testUpdateCommandValidatesSeoAndOldPrice(): void
    {
        $base = ['productId' => 1, 'storeId' => 1, 'marketId' => 1, 'locale' => 'uk-UA', 'name' => 'A', 'sku' => 'A-1', 'priceMinor' => 100, 'currency' => 'UAH', 'stockQuantity' => '1', 'unitCode' => 'item', 'categoryIds' => [], 'manualSlug' => null, 'shortDescription' => null, 'description' => null, 'gtin' => null, 'mpn' => null, 'status' => 'draft'];
        $ok = new UpdateProductCommand(...$base, metaTitle: 'Title', metaDescription: 'Desc', updateSeoMeta: true, compareAtMinor: 200, updateCompareAt: true);
        self::assertSame(200, $ok->compareAtMinor);
        $this->expectException(\InvalidArgumentException::class);
        new UpdateProductCommand(...$base, metaTitle: str_repeat('x', 256));
    }
}
