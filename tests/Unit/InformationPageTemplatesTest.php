<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Content\System\InformationPageTemplates;
use Commerce\Modules\Content\System\JurisdictionCatalog;
use PHPUnit\Framework\TestCase;

final class InformationPageTemplatesTest extends TestCase
{
    private const LOCALES = ['uk-UA', 'en-US', 'ru-RU', 'pl-PL', 'de-DE', 'da-DK'];
    private const KEYS = ['privacy', 'cookies', 'returns', 'terms'];

    public function testCountriesAreGroupedByLegalFrame(): void
    {
        self::assertSame('ua', JurisdictionCatalog::group('ua'));
        self::assertSame('eu', JurisdictionCatalog::group('DE'));
        self::assertSame('eu', JurisdictionCatalog::group('PL'));
        self::assertSame('gb', JurisdictionCatalog::group('GB'));
        self::assertSame('us', JurisdictionCatalog::group('US'));
        self::assertSame('other', JurisdictionCatalog::group('JP'));
    }

    public function testEveryFrameHasTextInEverySupportedLanguage(): void
    {
        foreach (['UA', 'DE', 'GB', 'US', 'JP'] as $country) {
            foreach (['uk', 'en', 'ru', 'pl', 'de', 'da', 'xx'] as $language) {
                $values = JurisdictionCatalog::values($country, $language);
                foreach (JurisdictionCatalog::KEYS as $key) {
                    self::assertNotSame('', $values[$key] ?? '', "$country/$language/$key");
                }
            }
        }
        self::assertStringContainsString('GDPR', JurisdictionCatalog::values('DE', 'en')['privacy_law']);
    }

    public function testDraftsRenderWithoutLeftoverPlaceholdersInAllLanguages(): void
    {
        $templates = new InformationPageTemplates(dirname(__DIR__, 2));
        $profile = ['store_name' => 'Shop <b>', 'legal_name' => 'Shop GmbH', 'address' => 'Street 1', 'email' => 'a@example.test', 'privacy_contact' => 'p@example.test', 'return_contact' => 'r@example.test'];
        foreach (self::LOCALES as $locale) {
            foreach (self::KEYS as $key) {
                if (!$templates->has($key, $locale)) {
                    continue;
                }
                $html = (string) $templates->body($key, $locale, $profile, 'DE');
                self::assertNotSame('', $html, "$locale/$key");
                self::assertDoesNotMatchRegularExpression('/\{(privacy_law|privacy_authority|consumer_law|cookie_law|store_name)\}/', $html, "$locale/$key");
                self::assertStringNotContainsString('Shop <b>', $html, 'store name must be escaped');
            }
        }
        // a German store gets the German draft
        self::assertSame('de-DE', InformationPageTemplates::directoryFor('de'));
        self::assertSame('en-US', InformationPageTemplates::directoryFor('ja'));
    }
}
