<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Fop\Application\FopService;
use PHPUnit\Framework\TestCase;

final class FopServiceTest extends TestCase
{
    public function testTheLimitIsTheMinimumWagesOfTheGroup(): void
    {
        $base = ['min_wage_minor' => 800000, 'limit_override_minor' => null];
        self::assertSame(167 * 800000, FopService::limitMinor($base + ['tax_group' => 1]));
        self::assertSame(834 * 800000, FopService::limitMinor($base + ['tax_group' => 2]));
        self::assertSame(1167 * 800000, FopService::limitMinor($base + ['tax_group' => 3]));
    }

    public function testAnOwnLimitReplacesTheCalculation(): void
    {
        self::assertSame(500000000, FopService::limitMinor(['tax_group' => 3, 'min_wage_minor' => 800000, 'limit_override_minor' => 500000000]));
    }
}
