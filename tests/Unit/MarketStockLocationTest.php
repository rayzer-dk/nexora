<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Catalog\Application\MarketStockLocation;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class MarketStockLocationTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('CREATE TABLE mc_inventory_location (id INTEGER PRIMARY KEY, status TEXT)');
        $this->db->executeStatement('CREATE TABLE mc_market_inventory_location (market_id INTEGER, location_id INTEGER, priority INTEGER)');
        foreach ([[10, 'active'], [11, 'disabled'], [12, 'active'], [13, 'active']] as [$id, $status]) {
            $this->db->insert('mc_inventory_location', ['id' => $id, 'status' => $status]);
        }
    }

    public function testTakesTheActiveWarehouseWithTheLowestPriority(): void
    {
        foreach ([[1, 11, 0], [1, 12, 5], [1, 10, 7]] as [$market, $location, $priority]) {
            $this->db->insert('mc_market_inventory_location', ['market_id' => $market, 'location_id' => $location, 'priority' => $priority]);
        }

        self::assertSame(12, MarketStockLocation::locationId($this->db, 1), 'The disabled warehouse 11 has the best priority but is skipped.');
    }

    public function testEqualPrioritiesFallBackToTheLowestLocationId(): void
    {
        foreach ([13, 12] as $location) {
            $this->db->insert('mc_market_inventory_location', ['market_id' => 1, 'location_id' => $location, 'priority' => 3]);
        }

        self::assertSame(12, MarketStockLocation::locationId($this->db, 1));
    }

    public function testMarketWithoutAnActiveWarehouseGetsZero(): void
    {
        $this->db->insert('mc_market_inventory_location', ['market_id' => 1, 'location_id' => 11, 'priority' => 0]);

        self::assertSame(0, MarketStockLocation::locationId($this->db, 1));
        self::assertSame(0, MarketStockLocation::locationId($this->db, 2));
    }
}
