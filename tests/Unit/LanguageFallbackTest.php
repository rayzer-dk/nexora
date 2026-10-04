<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\I18n\AdminInterfaceLocale;
use Commerce\Core\I18n\StorefrontUiTranslator;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class LanguageFallbackTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testATextMissingInALanguageIsShownInEnglishNotUkrainian(): void
    {
        $uk = include $this->root . '/resources/translations/uk-UA/storefront.php';
        $en = include $this->root . '/resources/translations/en-US/storefront.php';
        $pl = include $this->root . '/resources/translations/pl-PL/storefront.php';
        $missing = array_values(array_diff_key($uk, $pl));
        self::assertNotSame([], $missing, 'the fixture needs a language that misses some texts');
        $key = array_key_first(array_diff_key($uk, $pl));
        $translator = new StorefrontUiTranslator($this->root);

        self::assertSame($en[$key], $translator->translate($key, 'pl-PL'));
        self::assertSame($en[$key], $translator->catalogFor('pl-PL')[$key]);
        self::assertSame($pl['cart'] ?? $en['cart'], $translator->translate('cart', 'pl-PL'));
    }

    public function testALanguageWithoutFilesIsShownInEnglishAndAnUnknownKeyShowsTheKey(): void
    {
        $en = include $this->root . '/resources/translations/en-US/storefront.php';
        $translator = new StorefrontUiTranslator($this->root);

        self::assertSame($en['cart'], $translator->translate('cart', 'xx-XX'));
        self::assertSame('no.such.key', $translator->translate('no.such.key', 'xx-XX'));
    }

    public function testEnglishHasEveryKeyOfTheBaseLanguage(): void
    {
        foreach (glob($this->root . '/resources/translations/uk-UA/*.php') ?: [] as $file) {
            $en = $this->root . '/resources/translations/en-US/' . basename($file);
            self::assertFileExists($en);
            $missing = array_keys(array_diff_key(include $file, include $en));
            self::assertSame([], array_slice($missing, 0, 5), basename($file) . ' lacks English texts');
        }
    }

    public function testTheAdminLanguageFollowsTheSettingThenTheStoreThenEnglish(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE mc_system_setting (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
        $db->executeStatement('CREATE TABLE mc_store (id INTEGER PRIMARY KEY, default_locale TEXT)');
        $db->insert('mc_store', ['id' => 1, 'default_locale' => 'uk-UA']);
        $locales = static fn (): AdminInterfaceLocale => new AdminInterfaceLocale(dirname(__DIR__, 2), $db);

        self::assertSame('uk-UA', $locales()->siteDefault(), 'the store speaks Ukrainian and the admin does too');
        $db->update('mc_store', ['default_locale' => 'fr-FR'], ['id' => 1]);
        self::assertSame('en-US', $locales()->siteDefault(), 'no admin in the language of the store: English');
        $db->insert('mc_system_setting', ['setting_key' => 'admin.default_locale', 'setting_value' => 'uk-UA']);
        self::assertSame('uk-UA', $locales()->siteDefault(), 'the site setting wins');
        $db->update('mc_system_setting', ['setting_value' => 'xx-XX'], ['setting_key' => 'admin.default_locale']);
        self::assertSame('en-US', $locales()->siteDefault(), 'an unavailable language in the setting is ignored');
    }
}
