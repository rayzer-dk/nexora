<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Analytics\Dashboard\DashboardLayout;
use PHPUnit\Framework\TestCase;

final class DashboardLayoutTest extends TestCase
{
    public function testDefaultLayoutKeepsTheDefaultOrderAndHidesNothing(): void
    {
        $layout = DashboardLayout::normalize([], []);
        self::assertSame(DashboardLayout::WIDGETS, $layout['order']);
        self::assertSame([], $layout['hidden']);
    }

    public function testUnknownAndRepeatedKeysAreDroppedAndMissingOnesAreAppended(): void
    {
        $layout = DashboardLayout::normalize(['orders', 'nope', 'orders', 'kpis', 5], ['kpis', 'kpis', 'x']);
        self::assertSame(['orders', 'kpis'], array_slice($layout['order'], 0, 2));
        self::assertCount(count(DashboardLayout::WIDGETS), $layout['order']);
        self::assertSame(['kpis'], $layout['hidden']);
    }

    public function testGarbageInputFallsBackToTheDefaults(): void
    {
        self::assertSame(DashboardLayout::WIDGETS, DashboardLayout::normalize('x', null)['order']);
    }
}
