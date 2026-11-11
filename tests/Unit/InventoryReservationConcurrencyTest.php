<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;

/**
 * Real concurrency on a real MySQL/MariaDB: several PHP processes try to reserve the last units at the same moment.
 * Runs when DATABASE_URL points to a migrated database (the Database runtime QA job); otherwise it is skipped.
 */
final class InventoryReservationConcurrencyTest extends TestCase
{
    private const BUYERS = 8;
    private const STOCK = 3;

    private ?Connection $db = null;
    private int $itemId = 0;
    private int $locationId = 0;

    protected function setUp(): void
    {
        $url = (string) ($_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '');
        if (!str_starts_with($url, 'mysql://')) {
            self::markTestSkipped('A MySQL/MariaDB DATABASE_URL is needed.');
        }
        try {
            $this->db = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql']))->parse($url));
            $this->db->executeStatement('SET FOREIGN_KEY_CHECKS=0');
            $this->db->fetchOne('SELECT 1 FROM mc_stock_level LIMIT 1');
            $this->db->fetchOne('SELECT 1 FROM mc_inventory_reservation LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('The database is not migrated.');
        }
        $now = gmdate('Y-m-d H:i:s.u');
        $tag = bin2hex(random_bytes(6));
        $this->db->insert('mc_inventory_location', ['public_id' => random_bytes(16), 'store_id' => 1, 'code' => 'race-' . $tag, 'name' => 'Race', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $this->locationId = (int) $this->db->lastInsertId();
        $this->db->insert('mc_inventory_item', ['public_id' => random_bytes(16), 'sku' => 'RACE-' . $tag, 'unit_code' => 'unit', 'requires_shipping' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->itemId = (int) $this->db->lastInsertId();
        $this->db->insert('mc_stock_level', ['inventory_item_id' => $this->itemId, 'location_id' => $this->locationId, 'stocked_quantity' => self::STOCK, 'reserved_quantity' => 0, 'incoming_quantity' => 0, 'safety_stock' => 0, 'row_version' => 1, 'updated_at' => $now]);
    }

    protected function tearDown(): void
    {
        if ($this->db === null || $this->itemId === 0) {
            return;
        }
        $this->db->executeStatement('DELETE FROM mc_inventory_reservation WHERE inventory_item_id=?', [$this->itemId]);
        $this->db->executeStatement('DELETE FROM mc_stock_level WHERE inventory_item_id=?', [$this->itemId]);
        $this->db->executeStatement('DELETE FROM mc_inventory_item WHERE id=?', [$this->itemId]);
        $this->db->executeStatement('DELETE FROM mc_inventory_location WHERE id=?', [$this->locationId]);
    }

    public function testBuyersRacingForTheLastUnitsNeverOversell(): void
    {
        $url = (string) ($_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL'));
        $worker = dirname(__DIR__) . '/support/reserve_worker.php';
        $startAt = microtime(true) + 2.0;
        $processes = [];
        for ($buyer = 1; $buyer <= self::BUYERS; ++$buyer) {
            $command = [PHP_BINARY, $worker, $url, (string) $this->itemId, (string) $this->locationId, (string) $buyer, sprintf('%.4F', $startAt)];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $results[] = trim((string) stream_get_contents($pipes[1])) . trim((string) stream_get_contents($pipes[2]));
            proc_close($process);
        }
        $counts = array_count_values($results);

        self::assertSame(self::STOCK, $counts['OK'] ?? 0, 'exactly the stock is sold: ' . json_encode($counts));
        self::assertSame(self::BUYERS - self::STOCK, $counts['SOLD_OUT'] ?? 0, json_encode($counts));
        self::assertSame([], array_values(array_filter(array_keys($counts), static fn (string $r): bool => $r !== 'OK' && $r !== 'SOLD_OUT')), 'no buyer ended with an error: ' . json_encode($counts));
        $reserved = (string) $this->db?->fetchOne('SELECT reserved_quantity FROM mc_stock_level WHERE inventory_item_id=?', [$this->itemId]);
        self::assertSame(self::STOCK, (int) $reserved, 'reserved quantity equals what was sold');
        self::assertSame(self::STOCK, (int) $this->db?->fetchOne("SELECT COUNT(*) FROM mc_inventory_reservation WHERE inventory_item_id=? AND status='active'", [$this->itemId]));
    }
}
