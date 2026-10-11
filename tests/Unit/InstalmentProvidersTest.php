<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Payment\Domain\OnlinePaymentRequest;
use Commerce\Modules\Payment\Provider\MonoParts\MonoPartsClient;
use Commerce\Modules\Payment\Provider\MonoParts\MonoPartsPaymentProvider;
use Commerce\Modules\Payment\Provider\PrivatParts\PrivatPartsClient;
use Commerce\Modules\Payment\Provider\PrivatParts\PrivatPartsPaymentProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class InstalmentProvidersTest extends TestCase
{
    private function request(array $items, int $amount): OnlinePaymentRequest
    {
        return new OnlinePaymentRequest('01a10a54-0b29-7cb4-920a-2919bb90d597', 'DEFAULT-261011-ABC', $amount, 'UAH', 'https://shop.test/payment/return/x', 'https://shop.test/webhooks/payments/mono_parts', '+380501234567', 'Іван', $items);
    }

    public function testGoodsAddUpToTheAmountOrBecomeOneLine(): void
    {
        $match = $this->request([['name' => 'Чайник', 'quantity' => '2.000000', 'unit_price_minor' => 50000], ['name' => 'Кружка', 'quantity' => '1.000000', 'unit_price_minor' => 15000]], 115000);
        self::assertSame([['name' => 'Чайник', 'count' => 2, 'price_minor' => 50000], ['name' => 'Кружка', 'count' => 1, 'price_minor' => 15000]], $match->goodsForInstalments());
        $withShipping = $this->request([['name' => 'Чайник', 'quantity' => '1.000000', 'unit_price_minor' => 50000]], 55000);
        self::assertSame([['name' => '№ DEFAULT-261011-ABC', 'count' => 1, 'price_minor' => 55000]], $withShipping->goodsForInstalments());
        $fraction = $this->request([['name' => 'Кабель', 'quantity' => '1.500000', 'unit_price_minor' => 10000]], 15000);
        self::assertCount(1, $fraction->goodsForInstalments());
    }

    public function testMonoPartsSignatureIsBase64HmacSha256OfTheBody(): void
    {
        $body = '{"order_id":"abc"}';
        self::assertSame(base64_encode(hash_hmac('sha256', $body, 'secret', true)), MonoPartsClient::sign($body, 'secret'));
        $client = new MonoPartsClient(new MockHttpClient(), 'https://u2.test', 'store', 'secret');
        self::assertTrue($client->verify($body, MonoPartsClient::sign($body, 'secret')));
        self::assertFalse($client->verify($body . ' ', MonoPartsClient::sign($body, 'secret')));
    }

    public function testMonoPartsCreatesTheOrderWithSignedHeadersAndTheBankBody(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{"order_id":"bank-1"}', ['http_code' => 201]);
        });
        $provider = new MonoPartsPaymentProvider(new MonoPartsClient($http, 'https://u2.test', 'store-7', 'secret'), true, 'store-7', 'secret', '3,6,x,99,6', true);
        $session = $provider->createPayment($this->request([['name' => 'Чайник', 'quantity' => '2.000000', 'unit_price_minor' => 50000]], 100000));
        self::assertSame('bank-1', $session->providerReference);
        self::assertSame('POST', $seen['method']);
        self::assertSame('https://u2.test/api/order/create', $seen['url']);
        $headers = array_change_key_case(array_reduce($seen['options']['headers'], static function (array $carry, string $line): array {
            [$name, $value] = explode(': ', $line, 2);
            $carry[$name] = $value;

            return $carry;
        }, []));
        self::assertSame('store-7', $headers['store-id']);
        self::assertSame(MonoPartsClient::sign($seen['options']['body'], 'secret'), $headers['signature']);
        $body = json_decode($seen['options']['body'], true);
        self::assertSame('+380501234567', $body['client_phone']);
        self::assertSame(1000.0, (float) $body['total_sum']);
        self::assertSame([3, 6], $body['available_programs'][0]['available_parts_count']);
        self::assertSame('payment_installments', $body['available_programs'][0]['type']);
        self::assertSame(1000.0, (float) $body['products'][0]['sum']);
        self::assertSame('INTERNET', $body['invoice']['source']);
    }

    public function testMonoPartsStatesAndPhones(): void
    {
        self::assertSame('hold', MonoPartsPaymentProvider::status('IN_PROCESS', 'WAITING_FOR_STORE_CONFIRM'));
        self::assertSame('processing', MonoPartsPaymentProvider::status('IN_PROCESS', 'WAITING_FOR_CLIENT'));
        self::assertSame('success', MonoPartsPaymentProvider::status('SUCCESS', 'ACTIVE'));
        self::assertSame('reversed', MonoPartsPaymentProvider::status('SUCCESS', 'RETURNED'));
        self::assertSame('failure', MonoPartsPaymentProvider::status('FAIL', 'REJECTED_BY_CLIENT'));
        self::assertSame('+380501234567', MonoPartsPaymentProvider::normalizePhone('050 123-45-67'));
        self::assertSame('+380501234567', MonoPartsPaymentProvider::normalizePhone('+38 (050) 123 45 67'));
        self::assertSame('', MonoPartsPaymentProvider::normalizePhone('12345'));
    }

    public function testPrivatPartsSignsEveryFieldBetweenTheTwoPasswords(): void
    {
        $expected = base64_encode(sha1('pass' . 'STORE' . 'ORD-1' . '10050' . '2' . 'PP' . 'https://r' . 'https://b' . 'Чайник1' . '10050' . 'pass', true));
        self::assertSame($expected, PrivatPartsClient::sign('pass', ['STORE', 'ORD-1', '10050', '2', 'PP', 'https://r', 'https://b', 'Чайник1' . '10050']));
        self::assertSame(base64_encode(sha1('pass' . 'STORE' . 'ORD-1' . 'pass', true)), PrivatPartsClient::sign('pass', ['STORE', 'ORD-1']));
    }

    public function testPrivatPartsCreatesThePaymentAndTheBankPageAddress(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['url' => $url, 'body' => json_decode($options['body'], true)];

            return new MockResponse('{"state":"SUCCESS","token":"tok 1","orderId":"DEFAULT-261011-ABC"}', ['http_code' => 200]);
        });
        $provider = new PrivatPartsPaymentProvider(new PrivatPartsClient($http, 'https://payparts2.test', 'STORE', 'pass'), true, 'STORE', 'pass', 'II', '6');
        $session = $provider->createPayment($this->request([['name' => 'Чайник', 'quantity' => '1.000000', 'unit_price_minor' => 100500]], 100500));
        self::assertSame('DEFAULT-261011-ABC', $session->providerReference);
        self::assertSame('https://payparts2.test/ipp/v2/payment?token=tok%201', $session->redirectUrl);
        self::assertSame('https://payparts2.test/ipp/v2/payment/create', $seen['url']);
        $body = $seen['body'];
        self::assertSame('1005.00', $body['amount']);
        self::assertSame(6, $body['partsCount']);
        self::assertSame('II', $body['merchantType']);
        self::assertSame('1005.00', $body['products'][0]['price']);
        $signature = PrivatPartsClient::sign('pass', ['STORE', 'DEFAULT-261011-ABC', '100500', '6', 'II', 'https://shop.test/webhooks/payments/mono_parts', 'https://shop.test/payment/return/x', 'Чайник1100500']);
        self::assertSame($signature, $body['signature']);
    }

    public function testPrivatPartsStates(): void
    {
        self::assertSame('success', PrivatPartsPaymentProvider::status('SUCCESS'));
        self::assertSame('hold', PrivatPartsPaymentProvider::status('LOCKED'));
        self::assertSame('failure', PrivatPartsPaymentProvider::status('CANCELED'));
        self::assertSame('processing', PrivatPartsPaymentProvider::status('CLIENT_WAIT'));
    }
}
