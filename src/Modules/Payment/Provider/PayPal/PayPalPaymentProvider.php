<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Provider\PayPal;

use Commerce\Modules\Payment\Contract\OnlinePaymentProviderInterface;
use Commerce\Modules\Payment\Domain\OnlinePaymentRequest;
use Commerce\Modules\Payment\Domain\OnlinePaymentSession;
use Commerce\Modules\Payment\Domain\PaymentMethod;
use Commerce\Modules\Payment\Domain\RefundResult;

final readonly class PayPalPaymentProvider implements OnlinePaymentProviderInterface
{
    /** Currencies PayPal accepts with two decimals (zero-decimal ones are not offered). */
    private const CURRENCIES = ['USD', 'EUR', 'GBP', 'PLN', 'CZK', 'CHF', 'SEK', 'DKK', 'NOK', 'CAD', 'AUD'];

    public function __construct(private PayPalClient $client, private bool $isEnabled) {}

    public function method(): PaymentMethod
    {
        return new PaymentMethod('paypal', \Commerce\Core\I18n\CanonicalUiText::get('payment.method.paypal'), true, false);
    }

    public function enabled(): bool { return $this->isEnabled && $this->client->configured(); }

    public function createPayment(OnlinePaymentRequest $request): OnlinePaymentSession
    {
        if (!in_array($request->currency, self::CURRENCIES, true)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('payment.paypal.currency_unsupported'));
        }
        $order = $this->client->request('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $request->orderNumber,
                'custom_id' => $request->orderPublicId,
                'description' => 'Order ' . $request->orderNumber,
                'amount' => ['currency_code' => $request->currency, 'value' => number_format($request->amountMinor / 100, 2, '.', '')],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'return_url' => $request->returnUrl,
                'cancel_url' => $request->returnUrl,
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
            ]]],
        ], 'order-' . $request->orderPublicId);
        $id = trim((string) ($order['id'] ?? ''));
        $url = '';
        foreach ((array) ($order['links'] ?? []) as $link) {
            if (is_array($link) && in_array($link['rel'] ?? '', ['payer-action', 'approve'], true)) {
                $url = (string) ($link['href'] ?? '');
                break;
            }
        }
        if ($id === '' || $url === '') {
            throw new \RuntimeException('PayPal did not return an approval link.');
        }

        return new OnlinePaymentSession($id, $url, null, ['provider' => 'paypal']);
    }

    /**
     * Called when the buyer comes back from PayPal: takes the money. Returns the captured amount in minor units and the currency,
     * or null when the buyer has not approved the payment.
     *
     * @return array{status:string,amount_minor:int,currency:string,payload:array<string,mixed>}|null
     */
    public function capture(string $providerReference): ?array
    {
        $order = $this->client->request('GET', '/v2/checkout/orders/' . rawurlencode($providerReference));
        $state = (string) ($order['status'] ?? '');
        if ($state === 'APPROVED') {
            $order = $this->client->request('POST', '/v2/checkout/orders/' . rawurlencode($providerReference) . '/capture', null, 'capture-' . $providerReference);
            $state = (string) ($order['status'] ?? '');
        }
        if ($state !== 'COMPLETED') {
            return null;
        }
        $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? [];
        if (!is_array($capture) || ($capture['status'] ?? '') !== 'COMPLETED') {
            return ['status' => 'processing', 'amount_minor' => 0, 'currency' => '', 'payload' => $order];
        }

        return [
            'status' => 'success',
            'amount_minor' => (int) round(((float) ($capture['amount']['value'] ?? 0)) * 100),
            'currency' => strtoupper((string) ($capture['amount']['currency_code'] ?? '')),
            'payload' => $order,
        ];
    }

    public function refund(string $providerReference, int $amountMinor, string $idempotencyKey): RefundResult
    {
        $order = $this->client->request('GET', '/v2/checkout/orders/' . rawurlencode($providerReference));
        $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;
        if (!is_array($capture) || ($capture['id'] ?? '') === '') {
            throw new \RuntimeException('The PayPal payment was not captured, nothing to refund.');
        }
        $data = $this->client->request('POST', '/v2/payments/captures/' . rawurlencode((string) $capture['id']) . '/refund', [
            'amount' => ['currency_code' => (string) $capture['amount']['currency_code'], 'value' => number_format($amountMinor / 100, 2, '.', '')],
        ], 'refund-' . $idempotencyKey);
        $status = (string) ($data['status'] ?? 'PENDING');

        return new RefundResult($status === 'COMPLETED' ? 'refunded' : ($status === 'FAILED' || $status === 'CANCELLED' ? 'failed' : 'processing'), $data);
    }

    public function cancel(string $providerReference): void
    {
        // An approved-but-uncaptured PayPal order expires on its own; nothing to revoke.
    }
}
