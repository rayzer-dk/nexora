<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Extension\ExtensionDiagnostics;
use Commerce\Core\Extension\ExtensionMigrationRunner;
use Commerce\Core\Extension\ExtensionPackageManager;
use Commerce\Core\Extension\ExtensionSettingsSchemaValidator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Commerce\Tests\Unit\Support\ExtensionPackageManagerFactory;
use PHPUnit\Framework\TestCase;

final class ExtensionJournalDiagnosticsTest extends TestCase
{
    private Connection $db;
    private string $tmp;
    private ExtensionDiagnostics $diagnostics;
    private ExtensionPackageManager $manager;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/nexora-ext-diag-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/migrations', 0700, true);
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('CREATE TABLE mc_extension_installation (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, version TEXT, extension_type TEXT, api_version TEXT, status TEXT, install_path TEXT, manifest_json TEXT, failure_count INTEGER DEFAULT 0, last_error TEXT NULL)');
        $this->db->executeStatement('CREATE TABLE mc_extension_migration (id INTEGER PRIMARY KEY AUTOINCREMENT, installation_id INTEGER, migration_key TEXT, checksum_sha256 TEXT, applied_at TEXT)');
        $this->db->executeStatement('CREATE TABLE mc_extension_lifecycle_event (id INTEGER PRIMARY KEY AUTOINCREMENT, installation_id INTEGER NULL, code TEXT, version TEXT, action TEXT, from_status TEXT NULL, to_status TEXT NULL, result TEXT, message TEXT NULL, created_at TEXT)');
        $runner = new ExtensionMigrationRunner($this->db);
        $this->manager = ExtensionPackageManagerFactory::forInstalledModules($this->db, $runner, $this->tmp);
        $this->diagnostics = new ExtensionDiagnostics($this->db, $runner, new ExtensionSettingsSchemaValidator(), $this->manager);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/{,migrations/}*.*', GLOB_BRACE) ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp . '/migrations');
        @rmdir($this->tmp);
    }

    /** @param array<string,mixed> $manifest */
    private function install(string $code, array $manifest): int
    {
        $this->db->insert('mc_extension_installation', [
            'code' => $code, 'name' => ucfirst($code), 'version' => '1.0.0', 'extension_type' => 'trusted-module', 'api_version' => '1',
            'status' => 'active', 'install_path' => $this->tmp, 'manifest_json' => json_encode($manifest, JSON_THROW_ON_ERROR),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function testModuleWithoutSettingsSchemaOrMigrations(): void
    {
        $id = $this->install('plain', ['core' => '>=1.0', 'execution' => 'declarative', 'permissions' => ['catalog.view']]);

        $card = $this->diagnostics->for($id);

        self::assertSame('none', $card['settings']['state']);
        self::assertSame([], $card['migrations']);
        self::assertTrue($card['core']['compatible']);
        self::assertSame('not_required', $card['signature']['state']);
        self::assertSame(['catalog.view'], $card['permissions']);
    }

    public function testBrokenSettingsSchemaIsReportedNotThrown(): void
    {
        file_put_contents($this->tmp . '/settings.json', '{"schema_version":2,"fields":"nope"');
        $id = $this->install('broken', ['core' => '>=99.0', 'settings_schema' => 'settings.json']);

        $card = $this->diagnostics->for($id);

        self::assertSame('error', $card['settings']['state']);
        self::assertNotSame('', $card['settings']['message']);
        self::assertFalse($card['core']['compatible']);
    }

    public function testValidSettingsSchemaCountsFields(): void
    {
        file_put_contents($this->tmp . '/settings.json', json_encode(['schema_version' => 2, 'fields' => [['key' => 'title', 'type' => 'text', 'label' => 'Title']]]));
        $id = $this->install('withschema', ['settings_schema' => 'settings.json']);

        $card = $this->diagnostics->for($id);

        self::assertSame(['state' => 'ok', 'fields' => 1, 'message' => ''], $card['settings']);
    }

    public function testMigrationAppliedPendingAndChanged(): void
    {
        file_put_contents($this->tmp . '/migrations/001.php', '<?php return [];');
        file_put_contents($this->tmp . '/migrations/002.php', '<?php return [];');
        file_put_contents($this->tmp . '/migrations/003.php', '<?php return [];');
        $id = $this->install('migrating', ['execution' => 'trusted_release', 'migrations' => ['migrations/001.php', 'migrations/002.php', 'migrations/003.php']]);
        $now = '2026-10-18 10:00:00';
        $this->db->insert('mc_extension_migration', ['installation_id' => $id, 'migration_key' => 'migrations/001.php', 'checksum_sha256' => hash_file('sha256', $this->tmp . '/migrations/001.php'), 'applied_at' => $now]);
        $this->db->insert('mc_extension_migration', ['installation_id' => $id, 'migration_key' => 'migrations/002.php', 'checksum_sha256' => str_repeat('0', 64), 'applied_at' => $now]);

        $card = $this->diagnostics->for($id);

        self::assertSame(
            [
                ['key' => 'migrations/001.php', 'applied' => true, 'changed' => false],
                ['key' => 'migrations/002.php', 'applied' => true, 'changed' => true],
                ['key' => 'migrations/003.php', 'applied' => false, 'changed' => false],
            ],
            $card['migrations'],
        );
    }

    public function testLifecycleFiltersByModuleAndEvent(): void
    {
        $event = static fn (string $code, string $action, string $result): array => [
            'code' => $code, 'version' => '1.0.0', 'action' => $action, 'result' => $result, 'message' => null, 'created_at' => '2026-10-18 10:00:00',
        ];
        $this->db->insert('mc_extension_lifecycle_event', $event('alpha', 'install', 'success'));
        $this->db->insert('mc_extension_lifecycle_event', $event('alpha', 'activate', 'error'));
        $this->db->insert('mc_extension_lifecycle_event', $event('beta', 'install', 'success'));

        self::assertCount(2, $this->manager->lifecycle(100, 'alpha'));
        self::assertCount(2, $this->manager->lifecycle(100, null, 'install'));
        self::assertCount(1, $this->manager->lifecycle(100, 'alpha', 'install'));
        self::assertSame(['codes' => ['alpha', 'beta'], 'actions' => ['activate', 'install']], $this->manager->lifecycleFilters());
    }

    public function testUnknownInstallationIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->diagnostics->for(404);
    }
}
