<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Storefront\Infrastructure\DbalStorefrontCatalogQuery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * One product can have tracked and untracked variants. The buy action must
 * reflect the selected variant, not another variant of the same product.
 */
final class StorefrontAvailabilityParityTest extends TestCase
{
    /** @return array<string,mixed> */
    private function state(array $row): array
    {
        $catalog = (new ReflectionClass(DbalStorefrontCatalogQuery::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod(DbalStorefrontCatalogQuery::class, 'purchaseState'))->invoke($catalog, $row);
    }

    public function testTrackedVariantWithNoStockCannotBeAdded(): void
    {
        $state = $this->state(['product_type'=>'physical','manage_inventory'=>1,
            'available_quantity'=>'0','purchase_mode'=>'auto','allow_backorder'=>0]);

        self::assertFalse($state['purchase_allowed']);
        self::assertSame('https://schema.org/OutOfStock', $state['availability']);
    }

    public function testUntrackedVariantDoesNotDependOnAnotherVariant(): void
    {
        $state = $this->state(['product_type'=>'physical','manage_inventory'=>0,
            'available_quantity'=>'0','purchase_mode'=>'auto','allow_backorder'=>0]);

        self::assertTrue($state['purchase_allowed']);
        self::assertSame('https://schema.org/InStock', $state['availability']);
    }

    public function testTrackedPositiveStockAndBackorders(): void
    {
        self::assertTrue($this->state(['product_type'=>'physical','manage_inventory'=>1,
            'available_quantity'=>'3','purchase_mode'=>'auto','allow_backorder'=>0])['purchase_allowed']);
        self::assertTrue($this->state(['product_type'=>'physical','manage_inventory'=>1,
            'available_quantity'=>'0','purchase_mode'=>'auto','allow_backorder'=>1])['purchase_allowed']);
    }

    public function testExplicitSalesBlockOverridesAvailableStock(): void
    {
        foreach (['sold_out', 'notify', 'coming_soon', 'price_request'] as $mode) {
            self::assertFalse($this->state(['product_type'=>'physical','manage_inventory'=>0,
                'available_quantity'=>'100','purchase_mode'=>$mode,'allow_backorder'=>0])['purchase_allowed']);
        }
    }
}
