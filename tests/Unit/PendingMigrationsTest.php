<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Update\PendingMigrations;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class PendingMigrationsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }
        $this->dir = sys_get_temp_dir() . '/nexora-migrations-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/migrations', 0777, true);
        foreach (['Version20260101000000', 'Version20260201000000', 'Version20260301000000'] as $name) {
            file_put_contents($this->dir . '/migrations/' . $name . '.php', '<?php');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/migrations/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir . '/migrations');
        @rmdir($this->dir);
    }

    public function testNewerFilesThanTheDatabaseArePending(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE mc_migration_versions (version VARCHAR(191) PRIMARY KEY)');
        $db->insert('mc_migration_versions', ['version' => 'Commerce\\Migrations\\Version20260101000000']);
        $db->insert('mc_migration_versions', ['version' => 'Commerce\\Migrations\\Version20260201000000']);

        $pending = (new PendingMigrations($db, $this->dir))->pending();

        self::assertSame(['Commerce\\Migrations\\Version20260301000000'], $pending);
    }

    public function testNothingIsPendingWhenTheDatabaseHasCaughtUp(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE mc_migration_versions (version VARCHAR(191) PRIMARY KEY)');
        foreach (['Version20260101000000', 'Version20260201000000', 'Version20260301000000'] as $name) {
            $db->insert('mc_migration_versions', ['version' => 'Commerce\\Migrations\\' . $name]);
        }

        self::assertSame([], (new PendingMigrations($db, $this->dir))->pending());
    }
}
