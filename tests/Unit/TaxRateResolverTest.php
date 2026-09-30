<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Tax\Application\TaxRateResolver;
use PHPUnit\Framework\TestCase;

final class TaxRateResolverTest extends TestCase
{
    public function testIncludedTaxIsExtractedFromTheGrossPrice(): void
    {
        self::assertSame(2000, TaxRateResolver::includedTax(12000, 2000));
        self::assertSame(2300, TaxRateResolver::includedTax(12300, 2300));
        self::assertSame(1500, TaxRateResolver::includedTax(11500, 1500));
    }

    public function testNoTaxForZeroRateOrZeroPrice(): void
    {
        self::assertSame(0, TaxRateResolver::includedTax(10000, 0));
        self::assertSame(0, TaxRateResolver::includedTax(0, 2000));
        self::assertSame(0, TaxRateResolver::includedTax(-5, 2000));
    }

    public function testNetPlusTaxEqualsGross(): void
    {
        foreach ([99, 1000, 12345, 999999] as $gross) {
            foreach ([500, 2000, 2300, 2500] as $rate) {
                $tax = TaxRateResolver::includedTax($gross, $rate);
                self::assertGreaterThanOrEqual(0, $tax);
                self::assertLessThan($gross, $tax);
            }
        }
    }
}
