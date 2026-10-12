<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Tax\Application\VatReportService;
use Commerce\Modules\Tax\Application\ViesClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ViesClientTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['VIES_API_BASE'] = 'https://vies.test/rest-api';
    }

    protected function tearDown(): void
    {
        unset($_ENV['VIES_API_BASE']);
    }

    public function testAValidNumberIsNormalisedAndReturnsTheCompany(): void
    {
        $url = '';
        $client = new MockHttpClient(function (string $method, string $u) use (&$url): MockResponse {
            $url = $u;

            return new MockResponse(json_encode(['isValid' => true, 'userError' => 'VALID', 'name' => 'ACME  APS', 'address' => "VESTERGADE 1\n1456 KBH"]));
        });
        $r = (new ViesClient($client))->check('dk', 'DK 12-34 56 78');

        self::assertSame('https://vies.test/rest-api/ms/DK/vat/12345678', $url);
        self::assertTrue($r['valid']);
        self::assertSame('ACME APS', $r['name']);
        self::assertSame('VESTERGADE 1 1456 KBH', $r['address']);
    }

    public function testAnInvalidNumberAndAnUnavailableServiceAreTold(): void
    {
        $invalid = (new ViesClient(new MockHttpClient(new MockResponse('{"isValid":false,"userError":"INVALID","name":"---"}'))))->check('DE', '123456789');
        self::assertFalse($invalid['valid']);
        self::assertSame('INVALID', $invalid['state']);
        self::assertSame('', $invalid['name']);

        $down = (new ViesClient(new MockHttpClient(new MockResponse('{}', ['http_code' => 503]))))->check('DE', '123456789');
        self::assertSame('SERVICE_UNAVAILABLE', $down['state']);
    }

    public function testNonsenseNeverReachesTheNetwork(): void
    {
        $called = false;
        $client = new MockHttpClient(function () use (&$called): MockResponse {
            $called = true;

            return new MockResponse('{}');
        });
        self::assertSame('INVALID_INPUT', (new ViesClient($client))->check('UA', '12345678')['state']); // Ukraine is not in VIES
        self::assertSame('INVALID_INPUT', (new ViesClient($client))->check('DK', '../etc')['state']);
        self::assertFalse($called);
    }

    public function testQuartersCoverThreeMonths(): void
    {
        $q = VatReportService::quarter(2026, 2);
        self::assertSame('2026-04-01', $q['from']->format('Y-m-d'));
        self::assertSame('2026-07-01', $q['to']->format('Y-m-d'));
        self::assertSame('2027-01-01', VatReportService::quarter(2026, 4)['to']->format('Y-m-d'));
    }
}
