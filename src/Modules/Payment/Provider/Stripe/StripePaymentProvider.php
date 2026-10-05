<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Provider\Stripe;

use Commerce\Modules\Payment\Contract\OnlinePaymentProviderInterface;
use Commerce\Modules\Payment\Domain\OnlinePaymentRequest;
use Commerce\Modules\Payment\Domain\OnlinePaymentSession;
use Commerce\Modules\Payment\Domain\PaymentMethod;
use Commerce\Modules\Payment\Domain\RefundResult;

/** Card, Apple Pay and Google Pay on the Stripe-hosted Checkout page. Amounts are in minor units of two decimals. */
final readonly class StripePaymentProvider implements OnlinePaymentProviderInterface
{
    public function __construct(private StripeClient $client, private bool $isEnabled) {}

    public function method(): PaymentMethod
    {
        return new PaymentMethod('stripe', \Commerce\Core\I18n\CanonicalUiText::get('payment.method.stripe'), true, false);
    }

    public function enabled(): bool { return $this->isEnabled && $this->client->configured(); }

    public function createPayment(OnlinePaymentRequest $request): OnlinePaymentSession
    {
        $session = $this->client->post('/checkout/sessions', [
            'mode' => 'payment',
            'success_url' => $request->returnUrl,
            'cancel_url' => $request->returnUrl,
            'client_reference_id' => $request->orderPublicId,
            'expires_at' => time() + 3600,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => ['currency' => strtolower($request->currency), 'unit_amount' => $request->amountMinor, 'product_data' => ['name' => 'Order ' . $request->orderNumber]],
            ]],
            'payment_intent_data' => ['metadata' => ['order' => $request->orderNumber, 'order_public_id' => $request->orderPublicId]],
            'metadata' => ['order' => $request->orderNumber],
        ], 'checkout-' . $request->orderPublicId);
        $id = trim((string) ($session['id'] ?? ''));
        $url = trim((string) ($session['url'] ?? ''));
        if ($id === '' || $url === '') {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('payment.stripe.no_session'));
        }

        return new OnlinePaymentSession($id, $url, null, ['provider' => 'stripe']);
    }

    public function refund(string $providerReference, int $amountMinor, string $idempotencyKey): RefundResult
    {
        $session = $this->client->get('/checkout/sessions/' . rawurlencode($providerReference));
        $intent = trim((string) ($session['payment_intent'] ?? ''));
        if ($intent === '') {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('payment.stripe.nothing_to_refund'));
        }
        $data = $this->client->post('/refunds', ['payment_intent' => $intent, 'amount' => $amountMinor], 'refund-' . $idempotencyKey);
        $status = (string) ($data['status'] ?? 'pending');

        return new RefundResult($status === 'succeeded' ? 'refunded' : ($status === 'failed' || $status === 'canceled' ? 'failed' : 'processing'), $data);
    }

    public function cancel(string $providerReference): void
    {
        try {
            $this->client->post('/checkout/sessions/' . rawurlencode($providerReference) . '/expire', []);
        } catch (\RuntimeException) {
            // Already completed or expired: nothing to cancel.
        }
    }
}
