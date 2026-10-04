<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\I18n\LanguagePackService;
use Commerce\Core\I18n\TranslationCatalogLoader;
use DomainException;
use PHPUnit\Framework\TestCase;

final class LanguagePackServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nexora-langpack-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/resources/translations/uk-UA', 0777, true);
        mkdir($this->dir . '/resources/translations/en-US', 0777, true);
        file_put_contents($this->dir . '/resources/translations/uk-UA/storefront.php', "<?php\nreturn ['cart' => 'Кошик', 'items' => 'У кошику %count% товарів', 'it_s' => 'Це м\\'який текст'];\n");
        file_put_contents($this->dir . '/resources/translations/uk-UA/admin.php', "<?php\nreturn ['admin.save' => 'Зберегти', 'admin.count' => 'Всього: %count%'];\n");
        file_put_contents($this->dir . '/resources/translations/en-US/storefront.php', "<?php\nreturn ['cart' => 'Cart'];\n");
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($this->dir);
    }

    public function testExportOffersTheCurrentTextOrTheUkrainianFallback(): void
    {
        $texts = (new LanguagePackService($this->dir))->export('en-US');

        self::assertSame('Cart', $texts['cart']);
        self::assertSame('У кошику %count% товарів', $texts['items'], 'not translated yet: the Ukrainian text to translate');
    }

    public function testApostrophesAndQuotesNeedNoEscapingAndTheNewLanguageWorks(): void
    {
        $service = new LanguagePackService($this->dir);
        $json = json_encode(['cart' => "Panier d'achat", 'items' => '%count% articles dans le panier', 'it_s' => "C'est \"doux\""], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $result = $service->import('fr-FR', $json);

        self::assertSame(3, $result['saved']);
        self::assertSame(3, $result['translated']);
        $catalog = (new TranslationCatalogLoader($this->dir))->load('fr-FR');
        self::assertSame("Panier d'achat", $catalog['cart']);
        self::assertSame("C'est \"doux\"", $catalog['it_s']);
        self::assertTrue($service->exists('fr-FR'));
    }

    public function testBrokenLinesAreSkippedAndBrokenFilesAreRefused(): void
    {
        $service = new LanguagePackService($this->dir);
        $json = json_encode(['cart' => 'Panier', 'items' => 'Aucun marqueur', 'unknown' => 'x', 'it_s' => ['array']], JSON_THROW_ON_ERROR);

        $result = $service->import('fr-FR', $json);

        self::assertSame(1, $result['saved']);
        self::assertSame(['items' => 'placeholders', 'unknown' => 'unknown_key', 'it_s' => 'invalid_text'], $result['rejected']);
        $this->expectException(DomainException::class);
        $service->import('fr-FR', '{"cart": "Panier",');
    }

    public function testTheBaseLanguageAndBadCodesCannotBeReplaced(): void
    {
        $service = new LanguagePackService($this->dir);
        foreach (['uk-UA', '../etc', 'FR'] as $locale) {
            try {
                $service->import($locale, '{"cart":"x"}');
                self::fail('Expected a refusal for ' . $locale);
            } catch (DomainException) {
                self::assertFalse($service->exists($locale));
            }
        }
    }

    public function testAnAdminPackAddsAnInterfaceLanguageAndCoverageCountsOnlyRealTranslations(): void
    {
        $service = new LanguagePackService($this->dir);
        $db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        self::assertArrayNotHasKey('fr-FR', (new \Commerce\Core\I18n\AdminInterfaceLocale($this->dir, $db))->available());

        $service->import('fr-FR', json_encode(['admin.save' => 'Enregistrer', 'admin.count' => 'Total : %count%'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'admin');

        self::assertSame(100, $service->coverage('fr-FR', 'admin'));
        self::assertSame(0, $service->coverage('fr-FR', 'storefront'));
        self::assertSame('Enregistrer', (new TranslationCatalogLoader($this->dir))->load('fr-FR')['admin.save']);
        self::assertSame('fr-FR', array_key_last((new \Commerce\Core\I18n\AdminInterfaceLocale($this->dir, $db))->available()) ?? '');
        // the Ukrainian text pasted back is not a translation
        $service->import('de-DE', json_encode(['admin.save' => 'Зберегти'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'admin');
        self::assertSame(0, $service->coverage('de-DE', 'admin'));
    }

    public function testUnknownScopeIsRefused(): void
    {
        $this->expectException(DomainException::class);
        (new LanguagePackService($this->dir))->import('fr-FR', '{"x":"y"}', 'secrets');
    }

    public function testThePackOfTheOwnerWinsOverTheBundledTexts(): void
    {
        (new LanguagePackService($this->dir))->import('en-US', json_encode(['cart' => 'Basket'], JSON_THROW_ON_ERROR));

        self::assertSame('Basket', (new TranslationCatalogLoader($this->dir))->load('en-US')['cart']);
    }
}
