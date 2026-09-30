<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Provider\LiqPay;

use Commerce\Modules\Payment\Contract\OnlinePaymentProviderInterface;
use Commerce\Modules\Payment\Domain\OnlinePaymentRequest;
use Commerce\Modules\Payment\Domain\OnlinePaymentSession;
use Commerce\Modules\Payment\Domain\PaymentMethod;
use Commerce\Modules\Payment\Domain\RefundResult;

final readonly class LiqPayPaymentProvider implements OnlinePaymentProviderInterface
{
    private const CURRENCIES = ['UAH', 'USD', 'EUR'];

    public function __construct(private LiqPayClient $client, private bool $isEnabled) {}

    public function method(): PaymentMethod
    {
        return new PaymentMethod('liqpay', \Commerce\Core\I18n\CanonicalUiText::get('payment.method.liqpay'), true, false);
    }

    public function enabled(): bool { return $this->isEnabled && $this->client->configured(); }

    public function createPayment(OnlinePaymentRequest $request): OnlinePaymentSession
    {
        if (!in_array($request->currency, self::CURRENCIES, true)) {
            throw new \DomainException('liqpay_currency_unsupported');
        }
        $payload = [
            'version' => 3,
            'public_key' => $this->client->publicKey(),
            'action' => 'pay',
            'amount' => number_format($request->amountMinor / 100, 2, '.', ''),
            'currency' => $request->currency,
            'description' => 'Order ' . $request->orderNumber,
            'order_id' => $request->orderNumber,
            'result_url' => $request->returnUrl,
            'server_url' => $request->webhookUrl,
            'language' => 'uk',
            'expired_date' => gmdate('Y-m-d H:i:s', time() + 3600),
        ];
        if ($this->client->sandbox()) {
            $payload['sandbox'] = 1;
        }
        return new OnlinePaymentSession($request->orderNumber, $this->client->checkoutUrl($payload), null, ['provider' => 'liqpay']);
    }

    public function refund(string $providerReference, int $amountMinor, string $idempotencyKey): RefundResult
    {
        $data = $this->client->request([
            'action' => 'refund',
            'order_id' => $providerReference,
            'amount' => number_format($amountMinor / 100, 2, '.', ''),
        ]);
        $status = (string)($data['status'] ?? 'processing');
        return new RefundResult($status === 'reversed' ? 'refunded' : ($status === 'error' || $status === 'failure' ? 'failed' : 'processing'), $data);
    }

    public function cancel(string $providerReference): void
    {
        // LiqPay checkout links expire on their own (expired_date); nothing to revoke.
    }
}
