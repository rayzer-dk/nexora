<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Extension\ExtensionMigrationRunner;
use Commerce\Core\Extension\ExtensionPackageManager;
use Commerce\Core\Extension\ExtensionSettingsManager;
use Commerce\Core\Extension\ExtensionSettingsSchemaValidator;
use Commerce\Core\Security\SecretVault;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Commerce\Tests\Unit\Support\ExtensionPackageManagerFactory;
use PHPUnit\Framework\TestCase;

/**
 * The whole activation of an updated module version: the status switch, then the settings of the previous version.
 * SQLite rejects the row lock activate() takes (SELECT ... FOR UPDATE), so the connection drops that suffix; the
 * rest of activate() runs as in production.
 */
final class ExtensionPackageManagerActivateTest extends TestCase
{
    private Connection $db;
    private string $tmp;
    private ExtensionSettingsManager $settings;
    private ExtensionPackageManager $manager;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/nexora-ext-activate-' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0700, true);
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => RowLockFreeConnection::class]);
        $this->db->executeStatement('CREATE TABLE mc_extension_installation (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, version TEXT, extension_type TEXT, status TEXT, install_path TEXT, manifest_json TEXT, failure_count INTEGER DEFAULT 0, last_error TEXT NULL, activated_at TEXT NULL, disabled_at TEXT NULL)');
        $this->db->executeStatement('CREATE TABLE mc_extension_setting (installation_id INTEGER, setting_key TEXT, value_payload TEXT, is_secret INTEGER, updated_at TEXT, PRIMARY KEY (installation_id, setting_key))');
        $this->db->executeStatement('CREATE TABLE mc_extension_setting_revision (id INTEGER PRIMARY KEY AUTOINCREMENT, installation_id INTEGER, settings_json TEXT, actor TEXT, created_at TEXT)');
        $this->db->executeStatement('CREATE TABLE mc_locale (code TEXT PRIMARY KEY, name TEXT, native_name TEXT)');
        $this->db->executeStatement('CREATE TABLE mc_store_locale (store_id INTEGER, locale_code TEXT, enabled INTEGER, is_default INTEGER, sort_order INTEGER)');
        $this->settings = new ExtensionSettingsManager($this->db, new ExtensionSettingsSchemaValidator(), new SecretVault(str_repeat('k', 32)));
        $this->manager = ExtensionPackageManagerFactory::forInstalledModules($this->db, new ExtensionMigrationRunner($this->db), $this->tmp, $this->settings);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->tmp . '/*') ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->tmp);
    }

    public function testActivatingANewVersionCarriesTheSettingsOfTheActiveOne(): void
    {
        $old = $this->stage('1.0.0', 'active');
        $this->settings->save($old, ['title' => 'Kept'], 'admin@test');
        $new = $this->stage('1.1.0', 'staged');

        $this->manager->activate($new);

        self::assertSame('active', $this->statusOf($new));
        self::assertSame('disabled', $this->statusOf($old));
        self::assertSame(['title' => 'Kept'], $this->settings->activeSettings('example.ml'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_extension_setting WHERE installation_id=?', [$new]));
    }

    public function testActivatingWithRollbackAllowedDoesNotCarrySettingsOver(): void
    {
        $old = $this->stage('1.0.0', 'active');
        $this->settings->save($old, ['title' => 'Kept'], 'admin@test');
        $other = $this->stage('1.1.0', 'staged');

        $this->manager->activate($other, true);

        self::assertSame('active', $this->statusOf($other));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_extension_setting WHERE installation_id=?', [$other]));
        self::assertSame(['title' => 'Default'], $this->settings->activeSettings('example.ml'));
    }

    private function stage(string $version, string $status): int
    {
        $path = $this->tmp . '/' . $version;
        mkdir($path, 0700, true);
        file_put_contents($path . '/settings.schema.json', json_encode(['schema_version' => 2, 'fields' => [['key' => 'title', 'type' => 'text', 'label' => 'Title', 'default' => 'Default']]], JSON_THROW_ON_ERROR));
        $this->db->insert('mc_extension_installation', [
            'code' => 'example.ml', 'name' => 'Example', 'version' => $version, 'extension_type' => 'module', 'status' => $status,
            'install_path' => $path, 'manifest_json' => json_encode(['code' => 'example.ml', 'settings_schema' => 'settings.schema.json'], JSON_THROW_ON_ERROR),
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function statusOf(int $id): string
    {
        return (string) $this->db->fetchOne('SELECT status FROM mc_extension_installation WHERE id=?', [$id]);
    }
}

/** @internal test double: SQLite has no row locks. */
final class RowLockFreeConnection extends Connection
{
    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        return parent::fetchAssociative(str_replace(' FOR UPDATE', '', $query), $params, $types);
    }
}
