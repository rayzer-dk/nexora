<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Install\InstallRequest;
use Commerce\Core\Install\RegionCatalog;
use Commerce\Modules\Pricing\Infrastructure\EcbExchangeRateSource;
use PHPUnit\Framework\TestCase;

final class RegionAndRatesTest extends TestCase
{
    public function testCountryPresetProposesCurrencyLocaleAndZone(): void
    {
        $de = RegionCatalog::preset('de');
        self::assertSame(['DE', 'EUR', 'de-DE', 'Europe/Berlin', 1900], [$de['country'], $de['currency'], $de['locale'], $de['timezone'], $de['vat_bps']]);
        self::assertSame('OTHER', RegionCatalog::preset('ZZ')['country']);
        self::assertSame('USD', RegionCatalog::preset('US')['currency']);
    }

    public function testEveryPresetCurrencyAndLocaleIsKnown(): void
    {
        $currencies = RegionCatalog::currencies();
        foreach (RegionCatalog::countryCodes() as $country) {
            $preset = RegionCatalog::preset($country);
            self::assertArrayHasKey($preset['currency'], $currencies, $country);
            self::assertContains($preset['locale'], RegionCatalog::BUNDLED_LOCALES, $country);
            self::assertContains($preset['timezone'], \DateTimeZone::listIdentifiers(), $country);
        }
    }

    public function testInstallRequestFallsBackToThePreset(): void
    {
        $request = new InstallRequest('Shop', 'A', 'a@example.test', 'Long-Enough-Password-1', 'https://shop.example.test', 'shop', 'PL');
        self::assertSame(['PL', 'PLN', 'pl-PL', 'Europe/Warsaw'], [$request->countryCode(), $request->currencyCode(), $request->localeCode(), $request->timezoneName()]);
        $custom = new InstallRequest('Shop', 'A', 'a@example.test', 'Long-Enough-Password-1', 'https://shop.example.test', 'shop', 'PL', 'EUR', 'en-US', 'UTC');
        self::assertSame(['EUR', 'en-US', 'UTC'], [$custom->currencyCode(), $custom->localeCode(), $custom->timezoneName()]);
    }

    public function testInstallRequestRejectsUnknownLocale(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InstallRequest('Shop', 'A', 'a@example.test', 'Long-Enough-Password-1', 'https://shop.example.test', 'shop', 'PL', '', 'xx-XX');
    }

    public function testEcbDocumentIsParsed(): void
    {
        $xml = '<?xml version="1.0"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><Cube><Cube time="2026-09-29"><Cube currency="USD" rate="1.2"/><Cube currency="PLN" rate="4.3"/><Cube currency="JPY" rate="160"/><Cube currency="GBP" rate="0.85"/><Cube currency="CHF" rate="0.94"/></Cube></Cube></gesmes:Envelope>';
        $table = EcbExchangeRateSource::parse($xml);
        self::assertSame('2026-09-29', $table['date']);
        self::assertSame(1.2, $table['per_eur']['USD']);
        self::assertSame(1.0, $table['per_eur']['EUR']);
    }

    public function testBrokenEcbDocumentIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        EcbExchangeRateSource::parse('<html>not rates</html>');
    }
}
