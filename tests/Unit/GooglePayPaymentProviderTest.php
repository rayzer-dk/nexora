<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Payment\Domain\OnlinePaymentRequest;
use Commerce\Modules\Payment\Provider\GooglePay\GooglePayPaymentProvider;
use Commerce\Modules\Payment\Provider\Stripe\StripeClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GooglePayPaymentProviderTest extends TestCase
{
    private const SETTINGS = ['GOOGLE_PAY_ENABLED', 'GOOGLE_PAY_STRIPE_PUBLISHABLE_KEY', 'GOOGLE_PAY_ENVIRONMENT', 'GOOGLE_PAY_MERCHANT_ID'];

    protected function tearDown(): void
    {
        foreach (self::SETTINGS as $name) {
            unset($_ENV[$name]);
        }
    }

    /** @param list<array<string,mixed>> $responses @param list<array{url:string,body:string}> $seen */
    private function provider(array $responses, array &$seen = [], string $secret = 'sk_test_x'): GooglePayPaymentProvider
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$seen): MockResponse {
            $seen[] = ['url' => $url, 'body' => (string) ($options['body'] ?? '')];
            $next = array_shift($responses) ?? [];

            return new MockResponse(json_encode($next, JSON_THROW_ON_ERROR), ['http_code' => isset($next['error']) ? 402 : 200]);
        });

        return new GooglePayPaymentProvider(new StripeClient($client, $secret, 'whsec_x'));
    }

    public function testItIsOfferedOnlyWhenStripeAndTheGooglePayKeysAreSet(): void
    {
        self::assertFalse($this->provider([])->enabled());
        $_ENV['GOOGLE_PAY_ENABLED'] = '1';
        self::assertFalse($this->provider([])->enabled(), 'no publishable key');
        $_ENV['GOOGLE_PAY_STRIPE_PUBLISHABLE_KEY'] = 'pk_test_abc';
        self::assertTrue($this->provider([])->enabled());
        self::assertFalse($this->provider([], secret: '')->enabled(), 'Stripe itself is not connected');
        self::assertSame(['environment' => 'TEST', 'publishable_key' => 'pk_test_abc', 'merchant_id' => ''], $this->provider([])->clientConfig());
        $_ENV['GOOGLE_PAY_ENVIRONMENT'] = 'production';
        self::assertSame('PRODUCTION', $this->provider([])->clientConfig()['environment']);
    }

    public function testThePaymentStartsOnOurOwnPageNotAtAProvider(): void
    {
        $session = $this->provider([])->createPayment(new OnlinePaymentRequest('0194b0c0-0000-7000-8000-000000000001', 'N-1', 1000, 'UAH', 'https://shop.test/payment/return/0194b0c0-0000-7000-8000-000000000001', 'https://shop.test/hook'));

        self::assertSame('https://shop.test/payment/google-pay/0194b0c0-0000-7000-8000-000000000001', $session->redirectUrl);
        self::assertSame('gpay-0194b0c0-0000-7000-8000-000000000001', $session->providerReference);
    }

    public function testOnlyAStripeTokenIdIsTakenFromTheGoogleToken(): void
    {
        self::assertSame('tok_1Pabc12345XYZ', GooglePayPaymentProvider::stripeTokenId('{"id":"tok_1Pabc12345XYZ","card":{"last4":"4242"}}'));
        foreach (['', 'not json', '{"id":"pm_1Pabc12345XYZ"}', '{"id":"tok_short"}', '{"id":"tok_1Pabc12345XYZ&amount=1"}', '[]', '{"card":{}}'] as $bad) {
            self::assertNull(GooglePayPaymentProvider::stripeTokenId($bad), $bad);
        }
    }

    public function testASucceededIntentIsPaidAndCarriesTheOrderInItsMetadata(): void
    {
        $seen = [];
        $provider = $this->provider([['id' => 'pm_1'], ['id' => 'pi_1', 'status' => 'succeeded', 'amount_received' => 63950, 'currency' => 'uah']], $seen);

        $result = $provider->charge('tok_1Pabc12345XYZ', 'order-uuid', 'N-7', 63950, 'UAH', 'https://shop.test/payment/return/order-uuid');

        self::assertSame(['pi_1', 'paid', null, 63950, 'UAH'], [$result['id'], $result['status'], $result['redirect_url'], $result['amount_minor'], $result['currency']]);
        self::assertStringEndsWith('/payment_methods', $seen[0]['url']);
        self::assertStringContainsString('card%5Btoken%5D=tok_1Pabc12345XYZ', $seen[0]['body']);
        self::assertStringEndsWith('/payment_intents', $seen[1]['url']);
        foreach (['amount=63950', 'currency=uah', 'payment_method=pm_1', 'confirm=true', 'metadata%5Bchannel%5D=google_pay', 'metadata%5Border_public_id%5D=order-uuid'] as $part) {
            self::assertStringContainsString($part, $seen[1]['body']);
        }
    }

    public function testABankConfirmationSendsTheBuyerThereAndWithoutAnAddressItFails(): void
    {
        $bank = ['id' => 'pi_2', 'status' => 'requires_action', 'amount' => 1000, 'currency' => 'uah', 'next_action' => ['type' => 'redirect_to_url', 'redirect_to_url' => ['url' => 'https://hooks.stripe.com/3ds/x']]];
        $result = $this->provider([['id' => 'pm_1'], $bank])->charge('tok_1Pabc12345XYZ', 'o', 'N', 1000, 'UAH', 'https://shop.test/r');
        self::assertSame(['redirect', 'https://hooks.stripe.com/3ds/x'], [$result['status'], $result['redirect_url']]);

        unset($bank['next_action']);
        self::assertSame('failed', $this->provider([['id' => 'pm_1'], $bank])->charge('tok_1Pabc12345XYZ', 'o', 'N', 1000, 'UAH', 'https://shop.test/r')['status']);
        self::assertSame('processing', $this->provider([['id' => 'pm_1'], ['id' => 'pi_3', 'status' => 'processing']])->charge('tok_1Pabc12345XYZ', 'o', 'N', 1000, 'UAH', 'https://shop.test/r')['status']);
    }

    public function testADeclinedCardIsAnExceptionForTheController(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->provider([['error' => ['message' => 'Your card was declined.']]])->charge('tok_1Pabc12345XYZ', 'o', 'N', 1000, 'UAH', 'https://shop.test/r');
    }

    public function testOnlyAPaymentIntentCanBeRefunded(): void
    {
        $seen = [];
        $result = $this->provider([['status' => 'succeeded']], $seen)->refund('pi_9', 500, 'key');
        self::assertSame('refunded', $result->status);
        self::assertStringContainsString('payment_intent=pi_9', $seen[0]['body']);

        $this->expectException(\RuntimeException::class);
        $this->provider([])->refund('gpay-order-uuid', 500, 'key');
    }
}
