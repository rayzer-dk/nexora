<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit\Supplier;

use Commerce\Core\Security\SecretVault;
use Commerce\Modules\Supplier\Application\SupplierFeedParser;
use Commerce\Modules\Supplier\Application\SupplierService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class SupplierPriceTest extends TestCase
{
    private function service(): SupplierService
    {
        return new SupplierService($this->createStub(Connection::class), new SupplierFeedParser(), new MockHttpClient(), new SecretVault('test-secret-test-secret-test-secret'));
    }

    public function testMarkupAndRounding(): void
    {
        $s = $this->service();
        self::assertSame(12500, $s->price(100.0, 25.0, 'none'));
        self::assertSame(12300, $s->price(98.4, 25.0, 'whole'));
        self::assertSame(12000, $s->price(98.4, 25.0, 'tens'));
        self::assertSame(0, $s->price(10.0, -100.0, 'none'));
        self::assertSame(1999, $s->price(19.99, 0.0, 'none'));
    }
}
