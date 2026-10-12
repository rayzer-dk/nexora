<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Director\Application\DirectorService;
use PHPUnit\Framework\TestCase;

final class DirectorServiceTest extends TestCase
{
    public function testTheThreeMostUsefulActionsAreKeptInOrder(): void
    {
        $candidates = [
            ['kind' => 'slow_movers', 'score' => 140, 'params' => []],
            ['kind' => 'low_stock', 'score' => 660, 'params' => []],
            ['kind' => 'unpaid_orders', 'score' => 250, 'params' => []],
            ['kind' => 'stale_inquiries', 'score' => 360, 'params' => []],
        ];
        $top = DirectorService::pickTop($candidates, 3);

        self::assertSame(['low_stock', 'stale_inquiries', 'unpaid_orders'], array_column($top, 'kind'));
    }

    public function testNothingWithoutAScoreIsProposed(): void
    {
        self::assertSame([], DirectorService::pickTop([['kind' => 'low_stock', 'score' => 0, 'params' => []]], 3));
    }
}
