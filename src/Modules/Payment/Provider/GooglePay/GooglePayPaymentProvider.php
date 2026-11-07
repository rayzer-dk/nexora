<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Provider\GooglePay;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Integration\Application\IntegrationSecretStore;
use Commerce\Modules\Payment\Contract\OnlinePaymentProviderInterface;
use Commerce\Modules\Payment\Domain\OnlinePaymentRequest;
use Commerce\Modules\Payment\Domain\OnlinePaymentSession;
use Commerce\Modules\Payment\Domain\PaymentMethod;
use Commerce\Modules\Payment\Domain\RefundResult;
use Commerce\Modules\Payment\Provider\Stripe\StripeClient;

/**
 * Google Pay button on our own payment page. Google hands the browser a card token made for Stripe (Stripe is the payment
 * gateway of the Google Pay request); the server turns it into a Stripe PaymentIntent and confirms it. The order then
 * lives like any Stripe payment: the PaymentIntent id is the provider reference, refunds go through Stripe.
 */
final readonly class GooglePayPaymentProvider implements OnlinePaymentProviderInterface
{
    public function __construct(private StripeClient $stripe, private string $publicBaseUrl)
    {
    }

    public function method(): PaymentMethod
    {
        return new PaymentMethod('google_pay', CanonicalUiText::get('payment.method.google_pay'), true, false);
    }

    public function enabled(): bool
    {
        return $this->stripe->configured()
            && IntegrationSecretStore::effective('GOOGLE_PAY_ENABLED') === '1'
            && $this->publishableKey() !== '';
    }

    /** @return array{environment:string,publishable_key:string,merchant_id:string} */
    public function clientConfig(): array
    {
        $environment = strtoupper(IntegrationSecretStore::effective('GOOGLE_PAY_ENVIRONMENT')) === 'PRODUCTION' ? 'PRODUCTION' : 'TEST';

        return ['environment' => $environment, 'publishable_key' => $this->publishableKey(), 'merchant_id' => IntegrationSecretStore::effective('GOOGLE_PAY_MERCHANT_ID')];
    }

    public function createPayment(OnlinePaymentRequest $request): OnlinePaymentSession
    {
        // The money is taken on the payment page, once the buyer has chosen a card in Google Pay.
        return new OnlinePaymentSession('gpay-' . $request->orderPublicId, rtrim($this->publicBaseUrl, '/') . '/payment/google-pay/' . $request->orderPublicId, null, ['provider' => 'google_pay']);
    }

    /** The token Google returned: a Stripe token ("tok_…") inside a JSON string. */
    public static function stripeTokenId(string $googleToken): ?string
    {
        try {
            $data = json_decode($googleToken, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $id = is_array($data) ? (string) ($data['id'] ?? '') : '';

        return preg_match('/^tok_[A-Za-z0-9]{8,64}$/D', $id) === 1 ? $id : null;
    }

    /**
     * @return array{id:string,status:string,redirect_url:?string,amount_minor:int,currency:string,payload:array<string,mixed>}
     *         status: paid, redirect (the bank asks for a confirmation), processing or failed
     */
    public function charge(string $stripeTokenId, string $orderPublicId, string $orderNumber, int $amountMinor, string $currency, string $returnUrl): array
    {
        $method = $this->stripe->post('/payment_methods', ['type' => 'card', 'card' => ['token' => $stripeTokenId]]);
        $intent = $this->stripe->post('/payment_intents', [
            'amount' => $amountMinor,
            'currency' => strtolower($currency),
            'payment_method' => (string) ($method['id'] ?? ''),
            'payment_method_types' => ['card'],
            'confirm' => 'true',
            'return_url' => $returnUrl,
            'description' => 'Order ' . $orderNumber,
            'metadata' => ['channel' => 'google_pay', 'order' => $orderNumber, 'order_public_id' => $orderPublicId],
        ], 'gpay-' . $orderPublicId . '-' . substr(hash('sha256', $stripeTokenId), 0, 16));

        $status = match ((string) ($intent['status'] ?? '')) {
            'succeeded' => 'paid',
            'requires_action' => 'redirect',
            'processing' => 'processing',
            default => 'failed',
        };
        $redirect = $status === 'redirect' ? trim((string) ($intent['next_action']['redirect_to_url']['url'] ?? '')) : '';

        return [
            'id' => (string) ($intent['id'] ?? ''),
            'status' => $status === 'redirect' && $redirect === '' ? 'failed' : $status,
            'redirect_url' => $redirect !== '' ? $redirect : null,
            'amount_minor' => (int) ($intent['amount_received'] ?? $intent['amount'] ?? 0),
            'currency' => strtoupper((string) ($intent['currency'] ?? $currency)),
            'payload' => $intent,
        ];
    }

    public function refund(string $providerReference, int $amountMinor, string $idempotencyKey): RefundResult
    {
        if (!str_starts_with($providerReference, 'pi_')) {
            throw new \RuntimeException(CanonicalUiText::get('payment.stripe.nothing_to_refund'));
        }
        $data = $this->stripe->post('/refunds', ['payment_intent' => $providerReference, 'amount' => $amountMinor], 'refund-' . $idempotencyKey);
        $status = (string) ($data['status'] ?? 'pending');

        return new RefundResult($status === 'succeeded' ? 'refunded' : ($status === 'failed' || $status === 'canceled' ? 'failed' : 'processing'), $data);
    }

    public function cancel(string $providerReference): void
    {
        if (!str_starts_with($providerReference, 'pi_')) {
            return;
        }
        try {
            $this->stripe->post('/payment_intents/' . rawurlencode($providerReference) . '/cancel', []);
        } catch (\RuntimeException) {
            // Already paid, cancelled or expired: nothing to cancel.
        }
    }

    private function publishableKey(): string
    {
        return IntegrationSecretStore::effective('GOOGLE_PAY_STRIPE_PUBLISHABLE_KEY');
    }
}
