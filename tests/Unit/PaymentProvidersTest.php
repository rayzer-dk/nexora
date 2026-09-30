<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Payment\Domain\OnlinePaymentRequest;
use Commerce\Modules\Payment\Http\LiqPayWebhookController;
use Commerce\Modules\Payment\Http\WayForPayWebhookController;
use Commerce\Modules\Payment\Provider\LiqPay\LiqPayClient;
use Commerce\Modules\Payment\Provider\LiqPay\LiqPayPaymentProvider;
use Commerce\Modules\Payment\Provider\WayForPay\WayForPayClient;
use Commerce\Modules\Payment\Provider\WayForPay\WayForPayPaymentProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PaymentProvidersTest extends TestCase
{
    private function liqpay(?MockHttpClient $http = null): LiqPayClient
    {
        return new LiqPayClient($http ?? new MockHttpClient(), 'pub', 'priv', true);
    }

    public function testLiqPaySignatureMatchesDocumentedAlgorithm(): void
    {
        $data = base64_encode('{"a":1}');
        self::assertSame(base64_encode(sha1('priv' . $data . 'priv', true)), $this->liqpay()->sign($data));
    }

    public function testLiqPayVerifyRejectsTamperingAndAcceptsGenuine(): void
    {
        $c = $this->liqpay();
        $data = $c->encode(['order_id' => 'A-1', 'status' => 'success']);
        self::assertTrue($c->verify($data, $c->sign($data)));
        self::assertFalse($c->verify($data . 'x', $c->sign($data)));
        self::assertFalse($c->verify($data, ''));
        self::assertFalse((new LiqPayClient(new MockHttpClient(), 'pub', '', false))->verify($data, $c->sign($data)));
        self::assertTrue($c->verify($data, base64_encode(hash('sha3-256', 'priv' . $data . 'priv', true))));
    }

    public function testLiqPayCheckoutUrlCarriesSignedPayloadAndSandbox(): void
    {
        $provider = new LiqPayPaymentProvider($this->liqpay(), true);
        $session = $provider->createPayment(new OnlinePaymentRequest('pid', 'SHOP-1', 12345, 'UAH', 'https://s.test/return', 'https://s.test/webhooks/payments/liqpay'));
        self::assertSame('SHOP-1', $session->providerReference);
        parse_str((string) parse_url($session->redirectUrl, PHP_URL_QUERY), $q);
        $payload = $this->liqpay()->decode($q['data']);
        self::assertSame('123.45', $payload['amount']);
        self::assertSame(1, $payload['sandbox']);
        self::assertSame('https://s.test/webhooks/payments/liqpay', $payload['server_url']);
        self::assertTrue($this->liqpay()->verify($q['data'], $q['signature']));
    }

    public function testLiqPayRejectsUnsupportedCurrencyAndReportsDisabledWithoutKeys(): void
    {
        $provider = new LiqPayPaymentProvider($this->liqpay(), true);
        $this->expectException(\DomainException::class);
        $provider->createPayment(new OnlinePaymentRequest('pid', 'SHOP-1', 100, 'PLN', 'r', 'w'));
    }

    public function testLiqPayDisabledWithoutCredentials(): void
    {
        self::assertFalse((new LiqPayPaymentProvider(new LiqPayClient(new MockHttpClient(), '', '', false), true))->enabled());
        self::assertTrue((new LiqPayPaymentProvider($this->liqpay(), true))->enabled());
        self::assertFalse((new LiqPayPaymentProvider($this->liqpay(), false))->enabled());
    }

    public function testLiqPayRefundMapsReversed(): void
    {
        $provider = new LiqPayPaymentProvider($this->liqpay(new MockHttpClient(new MockResponse('{"status":"reversed","result":"ok"}'))), true);
        self::assertSame('refunded', $provider->refund('SHOP-1', 500, 'k')->status);
    }

    public function testLiqPayStatusMapping(): void
    {
        self::assertSame('success', LiqPayWebhookController::mapStatus('success'));
        self::assertSame('failure', LiqPayWebhookController::mapStatus('error'));
        self::assertSame('reversed', LiqPayWebhookController::mapStatus('reversed'));
        self::assertSame('hold', LiqPayWebhookController::mapStatus('hold_wait'));
        self::assertSame('processing', LiqPayWebhookController::mapStatus('wait_accept'));
    }

    private function wfp(?MockHttpClient $http = null): WayForPayClient
    {
        return new WayForPayClient($http ?? new MockHttpClient(), 'acc', 'secret', 'shop.test');
    }

    public function testWayForPayCallbackSignature(): void
    {
        $c = $this->wfp();
        $d = ['merchantAccount' => 'acc', 'orderReference' => 'SHOP-1', 'amount' => '10.00', 'currency' => 'UAH', 'authCode' => '123', 'cardPan' => '41****11', 'transactionStatus' => 'Approved', 'reasonCode' => '1100'];
        $d['merchantSignature'] = hash_hmac('md5', 'acc;SHOP-1;10.00;UAH;123;41****11;Approved;1100', 'secret');
        self::assertTrue($c->verifyCallback($d));
        $d['amount'] = '1.00';
        self::assertFalse($c->verifyCallback($d));
        self::assertFalse($c->verifyCallback(['merchantAccount' => 'other'] + $d));
    }

    public function testWayForPayCreatesInvoiceWithSignedRequest(): void
    {
        $sent = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent) {
            $sent = json_decode($options['body'], true);
            return new MockResponse('{"reason":"Ok","reasonCode":1100,"invoiceUrl":"https://secure.wayforpay.com/invoice/abc"}');
        });
        $provider = new WayForPayPaymentProvider($this->wfp($http), true);
        $session = $provider->createPayment(new OnlinePaymentRequest('pid', 'SHOP-1', 2500, 'UAH', 'https://s.test/r', 'https://s.test/webhooks/payments/wayforpay'));
        self::assertSame('https://secure.wayforpay.com/invoice/abc', $session->redirectUrl);
        self::assertSame('CREATE_INVOICE', $sent['transactionType']);
        self::assertSame('25.00', $sent['amount']);
        $expected = hash_hmac('md5', implode(';', ['acc', 'shop.test', 'SHOP-1', $sent['orderDate'], '25.00', 'UAH', 'Order SHOP-1', 1, '25.00']), 'secret');
        self::assertSame($expected, $sent['merchantSignature']);
    }

    public function testWayForPayRejectsNonHttpsInvoiceUrl(): void
    {
        $http = new MockHttpClient(new MockResponse('{"invoiceUrl":"http://evil.test/x"}'));
        $this->expectException(\RuntimeException::class);
        (new WayForPayPaymentProvider($this->wfp($http), true))->createPayment(new OnlinePaymentRequest('pid', 'SHOP-1', 100, 'UAH', 'r', 'w'));
    }

    public function testWayForPayStatusMapping(): void
    {
        self::assertSame('success', WayForPayWebhookController::mapStatus('Approved'));
        self::assertSame('failure', WayForPayWebhookController::mapStatus('Declined'));
        self::assertSame('reversed', WayForPayWebhookController::mapStatus('Refunded'));
        self::assertSame('processing', WayForPayWebhookController::mapStatus('InProcessing'));
    }
}
