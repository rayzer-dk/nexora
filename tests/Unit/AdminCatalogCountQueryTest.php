<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Catalog\Infrastructure\DbalCatalogAdminQuery;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class AdminCatalogCountQueryTest extends TestCase
{
    public function testCountOmitsUnusedJoinsWithoutTextSearch(): void
    {
        $this->assertCountQuery('', false);
    }

    public function testCountKeepsSearchJoinsWithTextSearch(): void
    {
        $this->assertCountQuery('phone', true);
    }

    private function assertCountQuery(string $search, bool $needsSearchJoins): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(2))->method('fetchOne')
            ->willReturnCallback(static function (string $sql, array $params) use ($needsSearchJoins): int {
                if (str_contains($sql, 'COUNT(DISTINCT p.id)')) {
                    self::assertSame($needsSearchJoins, str_contains($sql, 'LEFT JOIN mc_product_variant v'));
                    self::assertSame($needsSearchJoins, str_contains($sql, 'LEFT JOIN mc_brand b'));
                    self::assertSame([1, 'uk-UA'], array_slice($params, 0, 2));
                    self::assertStringContainsString('p.status IN (?)', $sql);
                    self::assertSame('published', $params[count($params) - 1]);

                    return 42;
                }
                self::assertStringContainsString('mc_market_inventory_location', $sql);

                return 0;
            });
        $db->expects(self::once())->method('fetchAllAssociative')->willReturn([]);

        $result = (new DbalCatalogAdminQuery($db))->products(
            1,
            2,
            'uk-UA',
            search: $search,
            filters: ['status' => ['published']],
        );

        self::assertSame(42, $result['total']);
        self::assertSame([], $result['items']);
    }
}
