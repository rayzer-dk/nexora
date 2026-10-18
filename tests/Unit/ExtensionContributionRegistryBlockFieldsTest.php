<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Extension\ExtensionContributionRegistry;
use Commerce\Core\Extension\ExtensionSettingsSchemaValidator;
use Commerce\Modules\Appearance\Builder\LayoutSchemaValidator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** The builder shows the fields of a module block from the block's `settings_schema`; a broken schema is logged, not fatal. */
final class ExtensionContributionRegistryBlockFieldsTest extends TestCase
{
    private Connection $db;
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nx-block-fields-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/blocks', 0777, true);
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('CREATE TABLE mc_extension_installation (id INTEGER PRIMARY KEY, code TEXT, version TEXT, install_path TEXT, manifest_json TEXT, status TEXT)');
        $this->db->executeStatement('CREATE TABLE mc_extension_lifecycle_event (id INTEGER PRIMARY KEY AUTOINCREMENT, installation_id INT NULL, code TEXT, version TEXT, action TEXT, from_status TEXT NULL, to_status TEXT NULL, result TEXT, message TEXT NULL, created_at TEXT)');
        $manifest = ['code' => 'acme.promo', 'blocks' => [
            ['id' => 'extension.acme_promo.card', 'surface' => 'home', 'label_key' => 'x', 'settings_schema' => 'blocks/card.schema.json'],
            ['id' => 'extension.acme_promo.broken', 'surface' => 'home', 'label_key' => 'x', 'settings_schema' => 'blocks/broken.schema.json'],
            ['id' => 'extension.acme_promo.plain', 'surface' => 'cart', 'label_key' => 'x'],
        ]];
        $this->db->insert('mc_extension_installation', ['id' => 7, 'code' => 'acme.promo', 'version' => '1.2.0', 'install_path' => $this->dir, 'manifest_json' => json_encode($manifest), 'status' => 'active']);
        file_put_contents($this->dir . '/blocks/card.schema.json', json_encode(['schema_version' => 2, 'fields' => [
            ['key' => 'heading', 'type' => 'multilingual_text', 'label' => 'Заголовок картки', 'group' => 'content'],
            ['key' => 'link', 'type' => 'url', 'label' => 'Посилання'],
            ['key' => 'count', 'type' => 'integer', 'label' => 'Кількість', 'min' => 1, 'max' => 9, 'default' => 3],
            ['key' => 'tone', 'type' => 'select', 'label' => 'Тон', 'options' => [['value' => 'light', 'label' => 'Світлий'], ['value' => 'dark', 'label' => 'Темний']], 'default' => 'light'],
            ['key' => 'api_key', 'type' => 'secret', 'label' => 'Ключ'],
        ]]));
        file_put_contents($this->dir . '/blocks/broken.schema.json', '{"schema_version": 2, "fields": [{"key": "x", "type": "video"');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/blocks/*') ?: []);
        rmdir($this->dir . '/blocks');
        rmdir($this->dir);
    }

    public function testFieldsComeFromTheBlockSchema(): void
    {
        $fields = $this->registry()->blockFields('extension.acme_promo.card');

        self::assertNotNull($fields);
        self::assertSame(['props.heading', 'props.link', 'props.count', 'props.tone'], array_column($fields, 'prop'), 'A secret never goes into a layout that the storefront prints.');
        self::assertSame(['multilingual_text', 'url', 'number', 'select'], array_column($fields, 'type'));
        self::assertSame('Заголовок картки', $fields[0]['label']);
        self::assertEquals(['min' => 1, 'max' => 9, 'step' => 1, 'default' => 3], array_intersect_key($fields[2], ['min' => 0, 'max' => 0, 'step' => 0, 'default' => 0]));
        self::assertSame([['value' => 'light', 'label' => 'Світлий'], ['value' => 'dark', 'label' => 'Темний']], $fields[3]['options']);
        self::assertSame([], $this->registry()->blockFields('extension.acme_promo.plain'), 'A block without a schema has only the common fields.');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_extension_lifecycle_event'));
    }

    public function testBrokenSchemaIsLoggedOnceAndGivesNoFields(): void
    {
        self::assertNull($this->registry()->blockFields('extension.acme_promo.broken'));
        self::assertNull($this->registry()->blockFields('extension.acme_promo.broken'));

        $events = $this->db->fetchAllAssociative('SELECT installation_id, code, version, action, result, message FROM mc_extension_lifecycle_event');
        self::assertCount(1, $events, 'Opening the builder again does not flood the log.');
        self::assertSame(['installation_id' => 7, 'code' => 'acme.promo', 'version' => '1.2.0', 'action' => 'block_schema_invalid', 'result' => 'error'], array_diff_key($events[0], ['message' => 0]));
        self::assertStringContainsString('extension.acme_promo.broken', (string) $events[0]['message']);
    }

    public function testSavingChecksModuleFieldValues(): void
    {
        $validator = new LayoutSchemaValidator($this->registry());
        $block = ['id' => 'promo', 'component' => 'extension.acme_promo.card', 'props' => ['heading' => ['uk-UA' => 'Знижки', 'en-US' => 'Sale'], 'link' => '/sale', 'count' => 4, 'tone' => 'dark']];
        $saved = $validator->validate(['schema_version' => 1, 'blocks' => [$block]], 'home', true);
        self::assertSame($block['props'], $saved['blocks'][0]['props']);

        $block['props']['link'] = 'javascript:alert(1)';
        $this->expectException(RuntimeException::class);
        $validator->validate(['schema_version' => 1, 'blocks' => [$block]], 'home', true);
    }

    private function registry(): ExtensionContributionRegistry
    {
        return new ExtensionContributionRegistry($this->db, new ExtensionSettingsSchemaValidator());
    }
}
