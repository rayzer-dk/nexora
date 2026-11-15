<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Database\ListOrder;
use PHPUnit\Framework\TestCase;

final class ListOrderTest extends TestCase
{
    private const COLUMNS = ['name' => 'pt.name', 'date' => 'o.created_at'];

    public function testAWhitelistedKeySortsInTheAskedDirectionWithATiebreaker(): void
    {
        self::assertSame('pt.name ASC,p.id DESC', ListOrder::sql(self::COLUMNS, 'name', 'asc', 'p.id DESC', 'p.id DESC'));
        self::assertSame('o.created_at DESC,p.id DESC', ListOrder::sql(self::COLUMNS, 'date', 'DESC', 'p.id DESC', 'p.id DESC'));
        self::assertSame('o.created_at DESC,p.id DESC', ListOrder::sql(self::COLUMNS, 'date', 'sideways', 'p.id DESC', 'p.id DESC'));
    }

    public function testAnythingElseKeepsTheListsOwnOrder(): void
    {
        self::assertSame('p.updated_at DESC,p.id DESC', ListOrder::sql(self::COLUMNS, '', 'asc', 'p.id DESC', 'p.updated_at DESC,p.id DESC'));
        self::assertSame('p.id DESC', ListOrder::sql(self::COLUMNS, 'name; DROP TABLE mc_product', 'asc', 'p.id DESC', 'p.id DESC'));
    }
}
