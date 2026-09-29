<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Shipping\Application\ShippingCountryService;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ShippingCountryServiceTest extends TestCase
{
    private function service(): ShippingCountryService
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE mc_shipping_country (store_id INTEGER, country_code TEXT, enabled INTEGER, PRIMARY KEY (store_id, country_code))');

        return new ShippingCountryService($db);
    }

    public function testUnconfiguredStoreDeliversEverywhere(): void
    {
        self::assertTrue($this->service()->allows(1, 'DE'));
    }

    public function testConfiguredStoreBlocksOtherCountries(): void
    {
        $service = $this->service();
        $service->saveCountries(1, ['ua', 'DK']);
        self::assertTrue($service->allows(1, 'UA'));
        self::assertTrue($service->allows(1, 'dk'));
        self::assertFalse($service->allows(1, 'DE'));
        self::assertTrue($service->allows(2, 'DE'), 'another store stays unrestricted');
    }

    public function testEmptyListAndInvalidCodesAreRejected(): void
    {
        $service = $this->service();
        $this->expectException(\DomainException::class);
        $service->saveCountries(1, ['XX1', 'ZZ']);
    }
}
