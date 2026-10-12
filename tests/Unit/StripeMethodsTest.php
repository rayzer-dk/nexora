<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Payment\Domain\OnlinePaymentRequest;
use Commerce\Modules\Payment\Provider\Stripe\StripeClient;
use Commerce\Modules\Payment\Provider\Stripe\StripePaymentProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StripeMethodsTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['STRIPE_PAYMENT_METHODS']);
    }

    public function testOnlyKnownMethodsAreKeptOnceEach(): void
    {
        self::assertSame(['card', 'ideal', 'blik', 'sepa_debit'], StripePaymentProvider::methodTypes(' Card, iDEAL ,blik,card,sepa_debit, not-a-method,;drop'));
        self::assertSame([], StripePaymentProvider::methodTypes(''));
    }

    public function testTheCheckoutSessionAsksForTheChosenMethods(): void
    {
        $body = '';
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = (string) $options['body'];

            return new MockResponse('{"id":"cs_test_1","url":"https://checkout.stripe.test/c/1"}');
        });
        $provider = new StripePaymentProvider(new StripeClient($http, 'sk_test', 'whsec'), true);
        $request = new OnlinePaymentRequest('01a10a54-0b29-7cb4-920a-2919bb90d597', 'DEFAULT-261011-ABC', 12000, 'EUR', 'https://shop.test/return', 'https://shop.test/hook');

        unset($_ENV['STRIPE_PAYMENT_METHODS']);
        $provider->createPayment($request);
        self::assertStringNotContainsString('payment_method_types', urldecode($body)); // Stripe chooses from the dashboard

        $_ENV['STRIPE_PAYMENT_METHODS'] = 'card,ideal,klarna';
        $provider->createPayment($request);
        $decoded = urldecode($body);
        self::assertStringContainsString('payment_method_types[0]=card', $decoded);
        self::assertStringContainsString('payment_method_types[1]=ideal', $decoded);
        self::assertStringContainsString('payment_method_types[2]=klarna', $decoded);
        self::assertStringContainsString('currency]=eur', $decoded);
    }
}
