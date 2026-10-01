<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Pricing\Application\CurrencyPriceSynchronizer;
use Commerce\Modules\Pricing\Application\ExchangeRateService;
use Commerce\Modules\Pricing\Contract\ReferenceRateSourceInterface;
use Commerce\Modules\Pricing\Domain\ReferenceRateTable;
use Commerce\Modules\Pricing\Infrastructure\ApiKeyExchangeRateSource;
use Commerce\Modules\Pricing\Infrastructure\CnbExchangeRateSource;
use Commerce\Modules\Pricing\Infrastructure\EcbExchangeRateSource;
use Commerce\Modules\Pricing\Infrastructure\NbpExchangeRateSource;
use Commerce\Modules\Pricing\Infrastructure\NbuExchangeRateSource;
use Commerce\Core\Store\StoreLocalizationSettings;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/** Parsers run on recorded fixtures: the tests never touch the network. */
final class ReferenceRateSourcesTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/Fixtures/rates/' . $name);
    }

    private function ecbTable(): ReferenceRateTable
    {
        $raw = EcbExchangeRateSource::parse($this->fixture('ecb-daily.xml'));
        $per = [];
        foreach ($raw['per_eur'] as $code => $perEur) {
            $per[$code] = 1 / $perEur;
        }

        return new ReferenceRateTable('ecb', 'EUR', $raw['date'], $per);
    }

    public function testEcbFixtureIsParsedAndCrossRatesAreDerived(): void
    {
        $table = $this->ecbTable();
        self::assertSame('2026-09-29', $table->date);
        self::assertEqualsWithDelta(4.25, (float) $table->cross('EUR', 'PLN'), 1e-9);
        // 1 PLN in CZK through the euro pivot: 24.3 / 4.25
        self::assertEqualsWithDelta(24.3 / 4.25, (float) $table->cross('PLN', 'CZK'), 1e-9);
        self::assertNull($table->cross('EUR', 'UAH'), 'the ECB does not publish the hryvnia');
        self::assertNull($table->cross('EUR', 'EUR'));
    }

    public function testEcbRejectsGarbage(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        EcbExchangeRateSource::parse('<html>maintenance</html>');
    }

    public function testNbpFixture(): void
    {
        $table = NbpExchangeRateSource::parse($this->fixture('nbp-table-a.json'));
        self::assertSame(['nbp', 'PLN', '2026-09-29'], [$table->source, $table->pivot, $table->date]);
        self::assertEqualsWithDelta(4.25, (float) $table->cross('EUR', 'PLN'), 1e-9);
        self::assertEqualsWithDelta(3.664 / 4.25, (float) $table->cross('USD', 'EUR'), 1e-9);
        $this->expectException(\UnexpectedValueException::class);
        NbpExchangeRateSource::parse('not json');
    }

    public function testCnbFixtureNormalisesUnitsOfOneHundred(): void
    {
        $table = CnbExchangeRateSource::parse($this->fixture('cnb-daily.txt'));
        self::assertSame('2026-09-29', $table->date);
        self::assertEqualsWithDelta(24.3, (float) $table->cross('EUR', 'CZK'), 1e-9);
        self::assertEqualsWithDelta(0.0612, (float) $table->cross('HUF', 'CZK'), 1e-9, 'the bank quotes HUF per 100 units');
        self::assertEqualsWithDelta(20.95 / 24.3, (float) $table->cross('USD', 'EUR'), 1e-9);
    }

    public function testNbuFixture(): void
    {
        $raw = NbuExchangeRateSource::parse(json_decode($this->fixture('nbu-exchange.json'), true, 16, JSON_THROW_ON_ERROR));
        self::assertSame('2026-09-29', $raw['date']);
        self::assertSame(48.6, $raw['uah_per_unit']['EUR']);
        $table = new ReferenceRateTable('nbu', 'UAH', $raw['date'], $raw['uah_per_unit']);
        self::assertEqualsWithDelta(48.6, (float) $table->cross('EUR', 'UAH'), 1e-9);
        self::assertEqualsWithDelta(1 / 48.6, (float) $table->cross('UAH', 'EUR'), 1e-12);
    }

    public function testApiSourceParsesBothServicesAndReportsRejectedKeys(): void
    {
        $host = ApiKeyExchangeRateSource::parse($this->fixture('exchangerate-host-live.json'), 'exchangerate_host');
        self::assertSame('USD', $host->pivot);
        self::assertEqualsWithDelta(0.862, (float) $host->cross('USD', 'EUR'), 1e-9);
        self::assertEqualsWithDelta(41.5 / 0.862, (float) $host->cross('EUR', 'UAH'), 1e-9);
        self::assertSame(gmdate('Y-m-d', 1790683199), $host->date);

        $api = ApiKeyExchangeRateSource::parse($this->fixture('exchangerate-api-latest.json'), 'exchangerate_api');
        self::assertEqualsWithDelta(3.664, (float) $api->cross('USD', 'PLN'), 1e-9);

        try {
            ApiKeyExchangeRateSource::parse($this->fixture('exchangerate-host-error.json'), 'exchangerate_host');
            self::fail('a rejected key must not produce a table');
        } catch (\UnexpectedValueException $e) {
            self::assertSame('api_rates_invalid_access_key', $e->getMessage());
        }
    }

    public function testServiceUsesTheChosenSourceAndFallsBackToTheNextOneInAutoMode(): void
    {
        $ecb = $this->stubSource('ecb', $this->ecbTable());
        $nbu = $this->stubSource('nbu', new ReferenceRateTable('nbu', 'UAH', '2026-09-29', ['UAH' => 1.0, 'EUR' => 48.6, 'USD' => 41.5, 'PLN' => 11.43, 'GBP' => 55.9, 'CZK' => 1.99]));
        $down = $this->stubSource('nbp', null);
        $service = new ExchangeRateService($this->createStub(Connection::class), [$ecb, $nbu, $down]);

        $result = $service->resolvePairs([
            ['base' => 'EUR', 'quote' => 'PLN', 'source' => 'auto'],
            ['base' => 'UAH', 'quote' => 'EUR', 'source' => 'auto'],
            ['base' => 'EUR', 'quote' => 'USD', 'source' => 'nbp'],
            ['base' => 'EUR', 'quote' => 'CZK', 'source' => 'nbu'],
        ]);
        $byPair = [];
        foreach ($result['rates'] as $row) {
            $byPair[$row['base'] . '/' . $row['quote']] = $row;
        }
        self::assertSame('ecb', $byPair['EUR/PLN']['provider']);
        self::assertSame('nbu', $byPair['UAH/EUR']['provider'], 'the ECB has no hryvnia, so auto falls through to the NBU');
        self::assertEqualsWithDelta(1 / 48.6, $byPair['UAH/EUR']['rate'], 1e-12);
        self::assertSame('nbu', $byPair['EUR/CZK']['provider']);
        self::assertSame(['EUR/USD'], $result['missing'], 'an unreachable chosen publisher leaves the pair without a rate');
        self::assertArrayHasKey('nbp', $result['errors']);
    }

    public function testSourceChoicesAreNormalised(): void
    {
        $service = new ExchangeRateService($this->createStub(Connection::class), [$this->stubSource('ecb', null), $this->stubSource('cnb', null)]);
        self::assertSame(['auto', 'ecb', 'cnb', 'manual'], $service->sourceChoices());
        self::assertSame('cnb', $service->normalizeSource(' CNB '));
        self::assertSame('auto', $service->normalizeSource('unknown'));
    }

    public function testMarkupAndRoundingHelpers(): void
    {
        self::assertSame(250, StoreLocalizationSettings::markupToBps('2,5'));
        self::assertSame(0, StoreLocalizationSettings::markupToBps('-3'));
        self::assertSame(StoreLocalizationSettings::MAX_MARKUP_BPS, StoreLocalizationSettings::markupToBps('99'));
        self::assertSame('44.000000000000', CurrencyPriceSynchronizer::withMarkup('40', 1000));
        self::assertSame('40', CurrencyPriceSynchronizer::withMarkup('40', 0));
    }

    private function stubSource(string $code, ?ReferenceRateTable $table): ReferenceRateSourceInterface
    {
        return new class ($code, $table) implements ReferenceRateSourceInterface {
            public function __construct(private string $code, private ?ReferenceRateTable $table)
            {
            }

            public function code(): string
            {
                return $this->code;
            }

            public function table(): ReferenceRateTable
            {
                return $this->table ?? throw new \RuntimeException('down');
            }
        };
    }
}
