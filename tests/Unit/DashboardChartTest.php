<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Analytics\Dashboard\DashboardChart;
use PHPUnit\Framework\TestCase;

final class DashboardChartTest extends TestCase
{
    public function testEmptySeriesHasNoData(): void
    {
        $chart = DashboardChart::layout([['day' => '2026-09-01', 'revenue_minor' => 0, 'orders' => 0], ['day' => '2026-09-02', 'revenue_minor' => 0, 'orders' => 0]], []);
        self::assertFalse($chart['has_data']);
    }

    public function testPointsStayInsideTheViewBoxAndAnnotationsBecomeMarks(): void
    {
        $series = [];
        foreach (range(1, 7) as $d) {
            $series[] = ['day' => sprintf('2026-09-%02d', $d), 'revenue_minor' => $d * 12345, 'orders' => $d];
        }
        $chart = DashboardChart::layout($series, [['day' => '2026-09-03', 'note' => 'Sale'], ['day' => '2020-01-01', 'note' => 'Outside']]);
        self::assertTrue($chart['has_data']);
        self::assertCount(7, $chart['points']);
        foreach ($chart['points'] as $p) {
            self::assertGreaterThanOrEqual(0, $p['x']);
            self::assertLessThanOrEqual($chart['w'], $p['x']);
            self::assertGreaterThanOrEqual(0, $p['y']);
            self::assertLessThanOrEqual($chart['h'], $p['y']);
        }
        self::assertCount(1, $chart['marks']);
        self::assertGreaterThanOrEqual(7 * 12345, $chart['max_minor']);
    }
}
