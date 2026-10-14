<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Event\DomainEvent;
use Commerce\Core\Event\DomainEventFactory;
use Commerce\Core\Event\EventBusInterface;
use Commerce\Core\Event\EventNames;
use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Catalog\Application\ProductStockService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ProductStockServiceTest extends TestCase
{
    private const SINGLE = '0190a000-0000-7000-8000-000000000001';
    private const MULTI = '0190a000-0000-7000-8000-000000000002';
    private const DIGITAL = '0190a000-0000-7000-8000-000000000003';
    private const OTHER_STORE = '0190a000-0000-7000-8000-000000000004';

    private Connection $db;

    /** @var list<DomainEvent> */
    private array $events = [];

    private function service(): ProductStockService
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE mc_product (id INTEGER PRIMARY KEY, public_id BLOB, status TEXT, product_type TEXT)');
        $db->executeStatement('CREATE TABLE mc_store_product (store_id INTEGER, product_id INTEGER, status TEXT)');
        $db->executeStatement('CREATE TABLE mc_product_variant (id INTEGER PRIMARY KEY, product_id INTEGER, status TEXT, sort_order INTEGER, manage_inventory INTEGER)');
        $db->executeStatement('CREATE TABLE mc_variant_inventory_item (variant_id INTEGER, inventory_item_id INTEGER, required_quantity TEXT)');
        $db->executeStatement('CREATE TABLE mc_inventory_location (id INTEGER PRIMARY KEY, status TEXT)');
        $db->executeStatement('CREATE TABLE mc_market_inventory_location (market_id INTEGER, location_id INTEGER, priority INTEGER)');
        $db->executeStatement('CREATE TABLE mc_stock_level (id INTEGER PRIMARY KEY AUTOINCREMENT, inventory_item_id INTEGER, location_id INTEGER, stocked_quantity TEXT, reserved_quantity TEXT, incoming_quantity TEXT, safety_stock TEXT, row_version INTEGER, updated_at TEXT)');
        // Market 1 has two warehouses: location 2 comes first by priority, location 1 is the fallback.
        $db->insert('mc_inventory_location', ['id' => 1, 'status' => 'active']);
        $db->insert('mc_inventory_location', ['id' => 2, 'status' => 'active']);
        $db->insert('mc_market_inventory_location', ['market_id' => 1, 'location_id' => 1, 'priority' => 20]);
        $db->insert('mc_market_inventory_location', ['market_id' => 1, 'location_id' => 2, 'priority' => 10]);
        // Product 1: one variant (item 11). Product 2: two active variants (items 21, 22) and a disabled one (item 23).
        // Product 3: digital, no inventory item. Product 4: belongs to another store.
        foreach ([[1, self::SINGLE, 'physical', 1], [2, self::MULTI, 'physical', 1], [3, self::DIGITAL, 'digital', 1], [4, self::OTHER_STORE, 'physical', 2]] as [$id, $publicId, $type, $store]) {
            $db->insert('mc_product', ['id' => $id, 'public_id' => Uuid::fromString($publicId)->toBinary(), 'status' => 'published', 'product_type' => $type]);
            $db->insert('mc_store_product', ['store_id' => $store, 'product_id' => $id, 'status' => 'active']);
        }
        foreach ([[101, 1, 'active', 0, 11], [201, 2, 'active', 0, 21], [202, 2, 'active', 1, 22], [203, 2, 'disabled', 2, 23], [301, 3, 'active', 0, null], [401, 4, 'active', 0, 41]] as [$variantId, $productId, $status, $sort, $item]) {
            $db->insert('mc_product_variant', ['id' => $variantId, 'product_id' => $productId, 'status' => $status, 'sort_order' => $sort, 'manage_inventory' => $item === null ? 0 : 1]);
            if ($item !== null) {
                $db->insert('mc_variant_inventory_item', ['variant_id' => $variantId, 'inventory_item_id' => $item, 'required_quantity' => '1.000000']);
            }
        }
        foreach ([[11, 2, '5.000000'], [11, 1, '40.000000'], [21, 2, '3.000000'], [22, 2, '4.500000'], [23, 2, '9.000000'], [41, 2, '8.000000']] as [$item, $location, $quantity]) {
            $db->insert('mc_stock_level', ['inventory_item_id' => $item, 'location_id' => $location, 'stocked_quantity' => $quantity, 'reserved_quantity' => '0.000000', 'incoming_quantity' => '0.000000', 'safety_stock' => '0.000000', 'row_version' => 3, 'updated_at' => '2026-01-01 00:00:00.000000']);
        }
        $this->db = $db;
        $this->events = [];
        $bus = $this->createStub(EventBusInterface::class);
        $bus->method('publish')->willReturnCallback(function (DomainEvent ...$events): void {
            foreach ($events as $event) {
                $this->events[] = $event;
            }
        });

        return new ProductStockService($db, $bus, new DomainEventFactory(new PublicIdFactory()));
    }

    private function stock(int $item, int $location): string
    {
        return (string) $this->db->fetchOne('SELECT stocked_quantity FROM mc_stock_level WHERE inventory_item_id=? AND location_id=?', [$item, $location]);
    }

    private function stockId(int $item, int $location): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM mc_stock_level WHERE inventory_item_id=? AND location_id=?', [$item, $location]);
    }

    public function testSetStockWritesTheTopPriorityWarehouseAndRemembersThePreviousValue(): void
    {
        $service = $this->service();
        $result = $service->setStock(1, 1, self::SINGLE, '7,5');

        self::assertTrue($result['ok']);
        self::assertSame('7.5', $result['quantity']);
        self::assertSame([$this->stockId(11, 2) => '5.000000'], $result['previous']);
        self::assertSame('7.500000', $this->stock(11, 2));
        self::assertSame('40.000000', $this->stock(11, 1), 'the second warehouse is not touched');
        self::assertSame(4, (int) $this->db->fetchOne('SELECT row_version FROM mc_stock_level WHERE id=?', [$this->stockId(11, 2)]));
        self::assertCount(1, $this->events);
        self::assertSame(EventNames::PRODUCT_UPDATED, $this->events[0]->eventName());
        self::assertSame(self::SINGLE, $this->events[0]->aggregateId());
        self::assertSame('stock', $this->events[0]->metadata()['source'] ?? null);
    }

    public function testSetStockRejectsTextNegativeAndTooPreciseNumbers(): void
    {
        $service = $this->service();
        foreach (['abc', '-1', '1.1234567', '', '1e3'] as $bad) {
            $result = $service->setStock(1, 1, self::SINGLE, $bad);
            self::assertFalse($result['ok'], $bad);
            self::assertSame('format', $result['error'] ?? null, $bad);
        }
        self::assertSame('5.000000', $this->stock(11, 2));
        self::assertSame([], $this->events);
    }

    public function testSetStockRefusesProductsWithSeveralVariantsOrWithoutInventory(): void
    {
        $service = $this->service();
        self::assertSame('variants', $service->setStock(1, 1, self::MULTI, '1')['error'] ?? null);
        self::assertSame('no_inventory', $service->setStock(1, 1, self::DIGITAL, '1')['error'] ?? null);
        self::assertSame('not_found', $service->setStock(1, 1, self::OTHER_STORE, '1')['error'] ?? null);
        self::assertSame('3.000000', $this->stock(21, 2));
        self::assertSame('8.000000', $this->stock(41, 2));
    }

    public function testZeroStockClearsEveryActiveVariantAndSkipsProductsWithoutInventory(): void
    {
        $service = $this->service();
        $result = $service->zeroStock(1, 1, [self::SINGLE, self::MULTI, self::DIGITAL]);

        self::assertSame(2, $result['updated']);
        self::assertSame(1, $result['skipped']);
        self::assertSame('0.000000', $this->stock(11, 2));
        self::assertSame('0.000000', $this->stock(21, 2));
        self::assertSame('0.000000', $this->stock(22, 2));
        self::assertSame('9.000000', $this->stock(23, 2), 'a disabled variant keeps its stock');
        self::assertSame('40.000000', $this->stock(11, 1), 'other warehouses keep their stock');
        $expected = [$this->stockId(11, 2) => '5.000000', $this->stockId(21, 2) => '3.000000', $this->stockId(22, 2) => '4.500000'];
        ksort($expected);
        $previous = $result['previous'];
        ksort($previous);
        self::assertSame($expected, $previous);
        self::assertCount(2, $this->events);
    }

    public function testZeroStockIgnoresProductsOfAnotherStore(): void
    {
        $service = $this->service();
        $result = $service->zeroStock(1, 1, [self::OTHER_STORE]);
        self::assertSame(0, $result['updated']);
        self::assertSame(1, $result['skipped']);
        self::assertSame('8.000000', $this->stock(41, 2));
    }

    public function testRestorePutsBackThePreviousQuantities(): void
    {
        $service = $this->service();
        $zeroed = $service->zeroStock(1, 1, [self::SINGLE, self::MULTI]);
        $restored = $service->restore($zeroed['previous'] + [999999 => '12.000000']);

        self::assertSame(3, $restored);
        self::assertSame('5.000000', $this->stock(11, 2));
        self::assertSame('3.000000', $this->stock(21, 2));
        self::assertSame('4.500000', $this->stock(22, 2));
    }
}
