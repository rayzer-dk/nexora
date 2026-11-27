<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit\Storefront;

use Commerce\Modules\Storefront\Infrastructure\CategoryTreeIndex;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class CategoryTreeIndexTest extends TestCase
{
    public function testBranchesReuseStoreIndexAndKeepBreadthFirstOrder(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(2))->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql, array $params): array {
                return $params === [1] ? [
                    ['id' => 1, 'parent_id' => null],
                    ['id' => 2, 'parent_id' => 1],
                    ['id' => 3, 'parent_id' => 1],
                    ['id' => 4, 'parent_id' => 2],
                    ['id' => 5, 'parent_id' => 6],
                    ['id' => 6, 'parent_id' => 5],
                ] : [
                    ['id' => 1, 'parent_id' => null],
                    ['id' => 7, 'parent_id' => 1],
                ];
            });
        $tree = new CategoryTreeIndex($db);

        self::assertSame([1, 2, 3, 4], $tree->scope(1, 1));
        self::assertSame([2, 4], $tree->scope(2, 1));
        self::assertSame([1, 2], $tree->ancestors(4, 1));
        self::assertSame([5, 6], $tree->scope(5, 1));
        self::assertSame([99], $tree->scope(99, 1));
        self::assertSame([1, 7], $tree->scope(1, 2));
    }
}
