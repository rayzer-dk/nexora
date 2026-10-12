<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Provider\Stripe;

use Commerce\Modules\Payment\Contract\OnlinePaymentProviderInterface;
use Commerce\Modules\Payment\Domain\OnlinePaymentRequest;
use Commerce\Modules\Payment\Domain\OnlinePaymentSession;
use Commerce\Modules\Payment\Domain\PaymentMethod;
use Commerce\Modules\Payment\Domain\RefundResult;

/**
 * Card, Apple Pay and Google Pay on the Stripe-hosted Checkout page; with STRIPE_PAYMENT_METHODS also iDEAL, BLIK, Klarna, SEPA debit and the other
 * local methods Stripe offers in Europe (they must be switched on in the Stripe dashboard too). Amounts are in minor units of two decimals.
 */
final readonly class StripePaymentProvider implements OnlinePaymentProviderInterface
{
    public function __construct(private StripeClient $client, private bool $isEnabled) {}

    public function method(): PaymentMethod
    {
        return new PaymentMethod('stripe', \Commerce\Core\I18n\CanonicalUiText::get('payment.method.stripe'), true, false);
    }

    public function enabled(): bool { return $this->isEnabled && $this->client->configured(); }

    /** The payment methods the owner listed (comma separated), only those Stripe Checkout knows; empty means "let Stripe choose from the dashboard". @return list<string> */
    public static function methodTypes(string $csv): array
    {
        $allowed = ['card', 'ideal', 'blik', 'klarna', 'sepa_debit', 'bancontact', 'p24', 'eps', 'link', 'paypal', 'twint', 'mb_way', 'multibanco', 'revolut_pay', 'swish', 'mobilepay'];
        $out = [];
        foreach (explode(',', strtolower($csv)) as $type) {
            $type = trim($type);
            if (in_array($type, $allowed, true) && !in_array($type, $out, true)) {
                $out[] = $type;
            }
        }

        return $out;
    }

    public function createPayment(OnlinePaymentRequest $request): OnlinePaymentSession
    {
        $types = self::methodTypes(\Commerce\Modules\Integration\Application\IntegrationSecretStore::effective('STRIPE_PAYMENT_METHODS'));
        $session = $this->client->post('/checkout/sessions', ($types !== [] ? ['payment_method_types' => $types] : []) + [
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
