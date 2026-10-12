<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Tax\Application\BuyerVatCheck;
use Commerce\Modules\Tax\Application\ViesClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BuyerVatCheckTest extends TestCase
{
    private int $calls = 0;

    private function check(string $json, int $code = 200): BuyerVatCheck
    {
        $this->calls = 0;

        return new BuyerVatCheck(new ViesClient(new MockHttpClient(function () use ($json, $code): MockResponse {
            ++$this->calls;

            return new MockResponse($json, ['http_code' => $code]);
        })));
    }

    public function testAValidNumberOfAnotherEuCountryMayBeZeroRated(): void
    {
        $r = $this->check('{"isValid":true,"userError":"VALID","name":"ACME APS"}')->check('DK 1234 5670', 'UA');
        self::assertSame(['status' => 'valid', 'name' => 'ACME APS'], $r);
    }

    public function testTheShopsOwnCountryAndNonEuNumbersNeverAskVies(): void
    {
        $c = $this->check('{}');
        self::assertSame('domestic', $c->check('PL1234567890', 'PL')['status']);
        self::assertSame('domestic', $c->check('EL123456789', 'GR')['status']);
        self::assertSame('not_eu', $c->check('1234567890', 'UA')['status']);
        self::assertSame('not_eu', $c->check('UA12345678', 'DK')['status']);
        self::assertSame(0, $this->calls);
    }

    public function testInvalidAndUnavailableAreDistinguished(): void
    {
        self::assertSame('invalid', $this->check('{"isValid":false,"userError":"INVALID"}')->check('DE123456789', 'UA')['status']);
        self::assertSame('unavailable', $this->check('{}', 503)->check('DE123456789', 'UA')['status']);
    }
}
