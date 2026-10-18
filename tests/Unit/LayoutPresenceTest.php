<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Appearance\Builder\LayoutPresence;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/** Who else has the same builder page open: seen in the last 90 s, of the same store and type; rows older than a day are removed. */
final class LayoutPresenceTest extends TestCase
{
    private Connection $db;

    private LayoutPresence $presence;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('CREATE TABLE mc_admin_user (id INTEGER PRIMARY KEY, display_name TEXT, email TEXT)');
        $this->db->executeStatement('CREATE TABLE mc_layout_presence (store_id INT, layout_type TEXT, admin_user_id INT, first_seen_at TEXT, last_seen_at TEXT, PRIMARY KEY (store_id, layout_type, admin_user_id))');
        foreach ([1 => 'Олена', 2 => 'Andrii', 3 => '', 4 => 'Ігор'] as $id => $name) {
            $this->db->insert('mc_admin_user', ['id' => $id, 'display_name' => $name, 'email' => 'admin' . $id . '@example.test']);
        }
        $this->presence = new LayoutPresence($this->db);
    }

    public function testOthersOnTheSamePageAreListedButNotTheCaller(): void
    {
        $this->presence->touch(1, 'home', 1);
        $this->presence->touch(1, 'home', 3);
        $this->presence->touch(1, 'cart', 4);
        $this->presence->touch(2, 'home', 4);

        $others = $this->presence->touch(1, 'home', 2);

        self::assertSame(['Олена', 'admin3@example.test'], array_column($others, 'name'));
        self::assertSame([], array_column($this->presence->touch(1, 'cart', 4), 'name'));
    }

    public function testAnEditorUnseenFor90SecondsIsGoneAndDayOldRowsAreDeleted(): void
    {
        $this->seen(1, gmdate('Y-m-d H:i:s.u', time() - 60), gmdate('Y-m-d H:i:s.u', time() - 60));
        $this->seen(2, gmdate('Y-m-d H:i:s.u', time() - 600), gmdate('Y-m-d H:i:s.u', time() - 91));
        $this->seen(4, gmdate('Y-m-d H:i:s.u', time() - 90000), gmdate('Y-m-d H:i:s.u', time() - 86500));

        self::assertSame(['Олена'], array_column($this->presence->touch(1, 'home', 3), 'name'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_layout_presence WHERE admin_user_id=4'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_layout_presence WHERE admin_user_id=2'));
    }

    public function testReturningAfterAnAbsenceRestartsTheSinceTime(): void
    {
        $this->seen(1, '2020-01-01 10:00:00.000000', gmdate('Y-m-d H:i:s.u', time() - 3600));
        $this->presence->touch(1, 'home', 1);

        $since = $this->presence->touch(1, 'home', 2)[0]['since'];
        self::assertGreaterThan(time() - 10, strtotime($since));
    }

    public function testLeavingRemovesTheEditorAtOnce(): void
    {
        $this->presence->touch(1, 'home', 1);
        $this->presence->leave(1, 'home', 1);

        self::assertSame([], $this->presence->touch(1, 'home', 2));
    }

    private function seen(int $adminId, string $first, string $last): void
    {
        $this->db->insert('mc_layout_presence', ['store_id' => 1, 'layout_type' => 'home', 'admin_user_id' => $adminId, 'first_seen_at' => $first, 'last_seen_at' => $last]);
    }
}
