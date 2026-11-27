<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit\Storefront;

use Commerce\Modules\Storefront\Domain\StorefrontContext;
use Commerce\Modules\Storefront\Projection\StorefrontFacetProjectionStore;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class FacetProjectionExpiryTest extends TestCase
{
    public function testProjectionCarriesRemainingLifetime(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchAssociative')
            ->willReturnCallback(static function (string $sql, array $params): array {
                self::assertStringContainsString('TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(6), expires_at)', $sql);
                self::assertSame([1, 2, 'uk-UA', 'UAH', 42], $params);
                return ['payload' => '{"brands":[]}', 'ttl' => 12];
            });
        $context = new StorefrontContext(1, 2, 'uk-UA', 'UAH', 'UA', 'Store');
        $store = new StorefrontFacetProjectionStore($db);

        self::assertSame(['payload' => ['brands' => []], 'ttl' => 12], $store->getEntry($context, 42));
    }
}
