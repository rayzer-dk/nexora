<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Storefront\Infrastructure\CategoryTreeIndex;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class CategoryTreeIndexTest extends TestCase
{
    private function index(): CategoryTreeIndex
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE mc_category (id INTEGER PRIMARY KEY, parent_id INTEGER NULL, status TEXT)');
        $db->executeStatement('CREATE TABLE mc_store_category (store_id INTEGER, category_id INTEGER, status TEXT)');
        // 1 > 2 > 3 > 4 (four levels), 1 > 5, a second root 6, and a hidden child 7 of 2
        foreach ([[1, null, 'active'], [2, 1, 'active'], [3, 2, 'active'], [4, 3, 'active'], [5, 1, 'active'], [6, null, 'active'], [7, 2, 'inactive']] as [$id, $parent, $status]) {
            $db->insert('mc_category', ['id' => $id, 'parent_id' => $parent, 'status' => $status]);
            $db->insert('mc_store_category', ['store_id' => 1, 'category_id' => $id, 'status' => 'active']);
        }

        return new CategoryTreeIndex($db);
    }

    public function testScopeHoldsTheWholeBranchAndSkipsInactiveCategories(): void
    {
        $index = $this->index();
        $scope = $index->scope(1, 1);
        sort($scope);
        $this->assertSame([1, 2, 3, 4, 5], $scope);
        $leaf = $index->scope(4, 1);
        $this->assertSame([4], $leaf);
        $this->assertSame([6], $index->scope(6, 1));
    }

    public function testAncestorsAreListedFromTheTopLevelDown(): void
    {
        $index = $this->index();
        $this->assertSame([1, 2, 3], $index->ancestors(4, 1));
        $this->assertSame([], $index->ancestors(1, 1));
        $this->assertSame([1], $index->ancestors(5, 1));
    }
}
