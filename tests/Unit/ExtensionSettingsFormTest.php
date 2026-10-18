<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Extension\ExtensionSettingsManager;
use Commerce\Core\Extension\ExtensionSettingsSchemaValidator;
use Commerce\Core\Security\SecretVault;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The module settings page is rendered from the real admin template and the posted form is fed back to the
 * settings manager, so a field the form cannot send the way the server expects shows up here.
 */
final class ExtensionSettingsFormTest extends TestCase
{
    private Connection $db;
    private string $tmp;
    private ExtensionSettingsManager $settings;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/nexora-ext-settings-' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0700, true);
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('CREATE TABLE mc_extension_installation (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, version TEXT, status TEXT, install_path TEXT, manifest_json TEXT, activated_at TEXT NULL)');
        $this->db->executeStatement('CREATE TABLE mc_extension_setting (installation_id INTEGER, setting_key TEXT, value_payload TEXT, is_secret INTEGER, updated_at TEXT, PRIMARY KEY (installation_id, setting_key))');
        $this->db->executeStatement('CREATE TABLE mc_extension_setting_revision (id INTEGER PRIMARY KEY AUTOINCREMENT, installation_id INTEGER, settings_json TEXT, actor TEXT, created_at TEXT)');
        $this->db->executeStatement('CREATE TABLE mc_locale (code TEXT PRIMARY KEY, name TEXT, native_name TEXT)');
        $this->db->executeStatement('CREATE TABLE mc_store_locale (store_id INTEGER, locale_code TEXT, enabled INTEGER, is_default INTEGER, sort_order INTEGER)');
        $this->db->insert('mc_locale', ['code' => 'uk-UA', 'name' => 'Ukrainian', 'native_name' => 'Українська']);
        $this->db->insert('mc_locale', ['code' => 'en-US', 'name' => 'English', 'native_name' => 'English']);
        $this->db->insert('mc_locale', ['code' => 'pl-PL', 'name' => 'Polish', 'native_name' => 'Polski']);
        $this->db->insert('mc_store_locale', ['store_id' => 1, 'locale_code' => 'en-US', 'enabled' => 1, 'is_default' => 0, 'sort_order' => 2]);
        $this->db->insert('mc_store_locale', ['store_id' => 1, 'locale_code' => 'uk-UA', 'enabled' => 1, 'is_default' => 1, 'sort_order' => 1]);
        $this->db->insert('mc_store_locale', ['store_id' => 1, 'locale_code' => 'pl-PL', 'enabled' => 0, 'is_default' => 0, 'sort_order' => 3]);
        $this->settings = new ExtensionSettingsManager($this->db, new ExtensionSettingsSchemaValidator(), new SecretVault(str_repeat('k', 32)));
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

    public function testMultilingualFieldsGetOneInputPerEnabledLanguageAndSurviveSaveAndReload(): void
    {
        $id = $this->install('1.0.0', [
            ['key' => 'greeting', 'type' => 'multilingual_text', 'label' => 'Greeting', 'required' => true],
            ['key' => 'notice', 'type' => 'multilingual_textarea', 'label' => 'Notice'],
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
        ]);

        $html = $this->render($id);
        self::assertStringContainsString('name="settings[greeting][uk-UA]"', $html);
        self::assertStringContainsString('name="settings[greeting][en-US]"', $html);
        self::assertStringContainsString('name="settings[notice][en-US]"', $html);
        self::assertStringNotContainsString('settings[greeting][pl-PL]', $html, 'A disabled store language gets no input.');

        $post = $this->formValues($html);
        $post['greeting']['uk-UA'] = 'Привіт';
        $post['greeting']['en-US'] = 'Hello';
        $post['notice']['en-US'] = "Line one\nLine two";
        $post['title'] = 'Plain';
        $this->settings->save($id, $post, 'admin@test');

        $html = $this->render($id);
        self::assertStringContainsString('Привіт', $html);
        self::assertStringContainsString('Hello', $html);
        self::assertStringContainsString("Line one\nLine two", $html);
        self::assertSame(['uk-UA' => 'Привіт', 'en-US' => 'Hello'], $this->settings->value('example.ml', 'greeting'));
        self::assertSame(['en-US' => "Line one\nLine two"], $this->settings->value('example.ml', 'notice'));

        // A second save of the unchanged form keeps every language.
        $this->settings->save($id, $this->formValues($html), 'admin@test');
        self::assertSame(['uk-UA' => 'Привіт', 'en-US' => 'Hello'], $this->settings->value('example.ml', 'greeting'));
    }

    public function testUpdatedVersionStartsWithTheSettingsOfThePreviousOneAndTheModuleCanReadThem(): void
    {
        $old = $this->install('1.0.0', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
            ['key' => 'columns', 'type' => 'integer', 'label' => 'Columns', 'default' => 3],
            ['key' => 'mode', 'type' => 'text', 'label' => 'Mode'],
            ['key' => 'api_key', 'type' => 'secret', 'label' => 'API key'],
            ['key' => 'legacy', 'type' => 'text', 'label' => 'Legacy'],
        ]);
        $this->settings->save($old, ['title' => 'Kept', 'columns' => '5', 'mode' => 'turbo', 'api_key' => 'sk-test-123', 'legacy' => 'old-only'], 'admin@test');
        $this->db->update('mc_extension_installation', ['status' => 'disabled'], ['id' => $old]);

        $new = $this->install('1.1.0', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
            ['key' => 'columns', 'type' => 'integer', 'label' => 'Columns', 'min' => 1, 'max' => 6],
            ['key' => 'mode', 'type' => 'select', 'label' => 'Mode', 'options' => [['value' => 'calm', 'label' => 'Calm'], ['value' => 'fast', 'label' => 'Fast']], 'default' => 'calm'],
            ['key' => 'api_key', 'type' => 'secret', 'label' => 'API key'],
            ['key' => 'badge', 'type' => 'text', 'label' => 'Badge', 'default' => 'New'],
        ]);
        self::assertSame(4, $this->settings->carryOver($new));

        $editor = array_column($this->settings->editor($new)['fields'], null, 'key');
        self::assertSame('Kept', $editor['title']['value']);
        self::assertSame(5, $editor['columns']['value']);
        self::assertSame('calm', $editor['mode']['value'], 'A value the new schema no longer accepts falls back to the new default.');
        self::assertTrue($editor['api_key']['has_value']);
        self::assertSame('New', $editor['badge']['value']);

        $all = $this->settings->activeSettings('example.ml');
        self::assertSame(['title' => 'Kept', 'columns' => 5, 'mode' => 'calm', 'api_key' => 'sk-test-123', 'badge' => 'New', 'legacy' => 'old-only'], $all);
        self::assertSame(0, $this->settings->carryOver($new), 'Settings are copied once; later edits of the new version are never overwritten.');

        $context = new \Commerce\Core\Extension\TrustedExtensionContext(
            'example.ml', '1.1.0', $this->tmp . '/1.1.0', new \Commerce\Core\Extension\TrustedExtensionRuntimeRegistry(),
            new \Commerce\Modules\Payment\Application\PaymentProviderRegistry([]), new \Commerce\Modules\Shipping\Application\DeliveryProviderRegistry([]),
            new \Commerce\Modules\ProductPage\Application\ProductBlockRegistry([]), new \Commerce\Modules\Ai\Application\AiProviderRegistry([]),
            new \Commerce\Modules\Ai\Application\TranslationProviderRegistry(), new \Commerce\Core\Extension\ExtensionServiceRegistry(),
            settings: $this->settings,
        );
        self::assertSame('Kept', $context->setting('title'));
        self::assertSame('sk-test-123', $context->setting('api_key'));
        self::assertSame('fallback', $context->setting('missing', 'fallback'));
        self::assertSame($all, $context->settings());

        $this->settings->save($new, ['title' => 'Edited', 'columns' => '2', 'mode' => 'fast', 'badge' => ''], 'admin@test');
        $saved = $context->settings();
        self::assertSame('Edited', $saved['title'], 'The module reads the latest save.');
        self::assertSame('sk-test-123', $saved['api_key'], 'An empty secret input keeps the stored secret.');
        self::assertSame('old-only', $saved['legacy'] ?? null, 'A key the new schema dropped survives a form save.');
    }

    public function testADowngradeOrAVersionWithItsOwnSettingsIsNotOverwritten(): void
    {
        $newer = $this->install('2.0.0', [['key' => 'title', 'type' => 'text', 'label' => 'Title']], 'disabled');
        $this->settings->save($newer, ['title' => 'From 2.0.0'], 'admin@test');
        $older = $this->install('1.0.0', [['key' => 'title', 'type' => 'text', 'label' => 'Title']]);
        self::assertSame(0, $this->settings->carryOver($older));
        self::assertNull($this->settings->value('example.ml', 'title'));
    }

    public function testAfterARollbackAndEditsTheUpdateStartsFromTheLastSavedSettings(): void
    {
        $one = $this->install('1.0.0', [['key' => 'title', 'type' => 'text', 'label' => 'Title']]);
        $this->settings->save($one, ['title' => 'One'], 'admin@test');
        $this->db->update('mc_extension_installation', ['status' => 'disabled'], ['id' => $one]);
        $two = $this->install('2.0.0', [['key' => 'title', 'type' => 'text', 'label' => 'Title']]);
        $this->settings->carryOver($two);
        $this->db->executeStatement("UPDATE mc_extension_setting SET updated_at='2026-01-01 10:00:00' WHERE installation_id=?", [$two]);
        // back to 1.0.0 and edited there
        $this->db->update('mc_extension_installation', ['status' => 'disabled'], ['id' => $two]);
        $this->db->update('mc_extension_installation', ['status' => 'active'], ['id' => $one]);
        $this->settings->save($one, ['title' => 'Edited on 1.0.0'], 'admin@test');
        $this->db->update('mc_extension_installation', ['status' => 'disabled'], ['id' => $one]);

        $next = $this->install('2.1.0', [['key' => 'title', 'type' => 'text', 'label' => 'Title']]);
        self::assertSame(1, $this->settings->carryOver($next));
        self::assertSame('Edited on 1.0.0', $this->settings->value('example.ml', 'title'));
    }

    public function testASecretTheNewVersionNoLongerAsksForIsNotCarriedKeptOrHandedOut(): void
    {
        $old = $this->install('1.0.0', [['key' => 'title', 'type' => 'text', 'label' => 'Title'], ['key' => 'old_key', 'type' => 'secret', 'label' => 'Old key'], ['key' => 'legacy', 'type' => 'text', 'label' => 'Legacy']]);
        $this->settings->save($old, ['title' => 'T', 'old_key' => 'sk-old', 'legacy' => 'L'], 'admin@test');
        $this->db->update('mc_extension_installation', ['status' => 'disabled'], ['id' => $old]);
        $new = $this->install('1.1.0', [['key' => 'title', 'type' => 'text', 'label' => 'Title']]);
        self::assertSame(2, $this->settings->carryOver($new), 'title and the plain legacy value; not the secret');
        self::assertSame(['title' => 'T', 'legacy' => 'L'], $this->settings->activeSettings('example.ml'));

        // a secret left over from before this rule is dropped by the next save and never handed to the module
        $this->db->insert('mc_extension_setting', ['installation_id' => $new, 'setting_key' => 'old_key', 'value_payload' => 'x', 'is_secret' => 1, 'updated_at' => 'x']);
        self::assertArrayNotHasKey('old_key', $this->settings->activeSettings('example.ml'));
        self::assertNull($this->settings->activeSetting('example.ml', 'old_key'));
        $this->settings->save($new, ['title' => 'T2'], 'admin@test');
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_extension_setting WHERE setting_key='old_key' AND installation_id=?", [$new]));
    }

    public function testASecretThatCannotBeDecryptedDoesNotWipeTheOtherSettings(): void
    {
        $id = $this->install('1.0.0', [['key' => 'title', 'type' => 'text', 'label' => 'Title'], ['key' => 'api_key', 'type' => 'secret', 'label' => 'API key'], ['key' => 'columns', 'type' => 'integer', 'label' => 'Columns', 'default' => 3]]);
        $this->settings->save($id, ['title' => 'Kept', 'api_key' => 'sk-1'], 'admin@test');
        // the vault key was replaced: the stored secret no longer decrypts
        $other = new ExtensionSettingsManager($this->db, new ExtensionSettingsSchemaValidator(), new SecretVault(str_repeat('z', 32)));
        self::assertSame(['title' => 'Kept', 'columns' => 3], $other->activeSettings('example.ml'));
        self::assertSame('fallback', $other->activeSetting('example.ml', 'api_key', 'fallback'));
        self::assertSame('Kept', $other->activeSetting('example.ml', 'title'));
        self::assertSame(3, $other->activeSetting('example.ml', 'columns'), 'a schema default answers for a value never saved');
    }

    /** @param list<array<string,mixed>> $fields */
    private function install(string $version, array $fields, string $status = 'active'): int
    {
        $path = $this->tmp . '/' . $version;
        @mkdir($path, 0700, true);
        file_put_contents($path . '/settings.schema.json', json_encode(['schema_version' => 2, 'fields' => $fields], JSON_THROW_ON_ERROR));
        $this->db->insert('mc_extension_installation', [
            'code' => 'example.ml', 'name' => 'Multilingual', 'version' => $version, 'status' => $status,
            'install_path' => $path, 'manifest_json' => json_encode(['code' => 'example.ml', 'settings_schema' => 'settings.schema.json'], JSON_THROW_ON_ERROR),
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function render(int $id): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 2) . '/themes/default/templates', 'storefront');
        $twig = new Environment($loader, ['strict_variables' => false, 'autoescape' => 'html']);
        $twig->registerUndefinedFunctionCallback(static fn (string $name): TwigFunction => new TwigFunction($name, static fn (mixed ...$args): string => $name === 'ui_text' ? (string) ($args[0] ?? '') : ''));
        $twig->registerUndefinedFilterCallback(static fn (string $name): TwigFilter => new TwigFilter($name, static fn (mixed $value): mixed => $value));
        $twig->addGlobal('app', new TemplateStub());

        return $twig->render('@storefront/admin/system/extension_settings.html.twig', ['platform_version' => 'test', ...$this->settings->editor($id)]);
    }

    /** Reads the controls of the settings form the way a browser submits them. @return array<string,mixed> */
    private function formValues(string $html): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        $pairs = [];
        foreach ((new \DOMXPath($dom))->query('//form[@data-dirty-guard]//*[self::input or self::textarea or self::select][starts-with(@name,"settings[")]') ?: [] as $control) {
            if (!$control instanceof \DOMElement) {
                continue;
            }
            $type = strtolower($control->getAttribute('type'));
            if ($type === 'checkbox' && !$control->hasAttribute('checked')) {
                continue;
            }
            $value = match ($control->tagName) {
                'textarea' => $control->textContent,
                'select' => (string) ((new \DOMXPath($dom))->query('.//option[@selected]', $control)?->item(0)?->getAttribute('value') ?? ''),
                default => $control->getAttribute('value'),
            };
            $pairs[] = rawurlencode($control->getAttribute('name')) . '=' . rawurlencode($value);
        }
        parse_str(implode('&', $pairs), $parsed);

        return is_array($parsed['settings'] ?? null) ? $parsed['settings'] : [];
    }
}

/** Minimal stand-in for Twig's `app` variable: every attribute exists, is empty and prints as nothing. */
final class TemplateStub implements \IteratorAggregate, \Countable
{
    public function __get(string $name): self
    {
        return $this;
    }

    public function __isset(string $name): bool
    {
        return true;
    }

    /** @param list<mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return $name === 'get' && array_key_exists(1, $args) ? $args[1] : $this;
    }

    public function __toString(): string
    {
        return '';
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator([]);
    }

    public function count(): int
    {
        return 0;
    }
}
