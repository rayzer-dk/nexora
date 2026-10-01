<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Admin\Http\AdminFilterValues;
use Commerce\Modules\Storefront\Domain\ProductCatalogFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class FilterListsTest extends TestCase
{
    public function testBrandIdsAcceptOneListOrTheOldSingleValue(): void
    {
        self::assertSame([5], ProductCatalogFilter::ids('5'));
        self::assertSame([3, 5, 9], ProductCatalogFilter::ids(['9', '5', '3', '5']));
        self::assertSame([2, 4], ProductCatalogFilter::ids('4,2'));
        self::assertSame([], ProductCatalogFilter::ids(['x', '0', '-1', '', ['1']]));
        self::assertSame([], ProductCatalogFilter::ids(null));
        self::assertCount(24, ProductCatalogFilter::ids(range(1, 40)));
    }

    public function testFilterKnowsAboutSeveralBrands(): void
    {
        self::assertFalse((new ProductCatalogFilter())->isFiltered());
        $filter = new ProductCatalogFilter(brandIds: [1, 2]);
        self::assertTrue($filter->isFiltered());
        self::assertTrue($filter->isFilteredExceptSearch());
        $this->expectException(\InvalidArgumentException::class);
        new ProductCatalogFilter(brandIds: [0]);
    }

    public function testAdminListFilterReadsListsAndOldSingleValues(): void
    {
        $request = Request::create('/admin/orders?status[]=placed&status[]=bogus&status[]=placed&payment_status=paid');
        self::assertSame(['placed'], AdminFilterValues::list($request, 'status', ['placed', 'completed']));
        self::assertSame(['paid'], AdminFilterValues::list($request, 'payment_status'));
        self::assertSame([], AdminFilterValues::list($request, 'missing'));

        $where = ['a=1'];
        $params = [7];
        AdminFilterValues::in('o.status', ['placed', 'completed'], $where, $params);
        AdminFilterValues::in('o.other', [], $where, $params);
        self::assertSame(['a=1', 'o.status IN (?,?)'], $where);
        self::assertSame([7, 'placed', 'completed'], $params);
    }
}
