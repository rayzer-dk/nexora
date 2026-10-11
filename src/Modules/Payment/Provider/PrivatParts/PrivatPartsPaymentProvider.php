<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Provider\PrivatParts;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Payment\Contract\OnlinePaymentProviderInterface;
use Commerce\Modules\Payment\Contract\PollablePaymentProviderInterface;
use Commerce\Modules\Payment\Domain\OnlinePaymentRequest;
use Commerce\Modules\Payment\Domain\OnlinePaymentSession;
use Commerce\Modules\Payment\Domain\PaymentMethod;
use Commerce\Modules\Payment\Domain\RefundResult;

final readonly class PrivatPartsPaymentProvider implements OnlinePaymentProviderInterface, PollablePaymentProviderInterface
{
    public function __construct(private PrivatPartsClient $client, private bool $isEnabled, private string $storeId, private string $password, private string $merchantType, private string $parts)
    {
    }

    public function method(): PaymentMethod
    {
        return new PaymentMethod('privat_parts', CanonicalUiText::get('payment.privat_parts.name'), true);
    }

    public function enabled(): bool
    {
        return $this->isEnabled && $this->storeId !== '' && $this->password !== '';
    }

    public function partsCount(): int
    {
        $count = (int) $this->parts;

        return $count >= 2 && $count <= 24 ? $count : 2;
    }

    public function merchantType(): string
    {
        return in_array($this->merchantType, ['PP', 'II'], true) ? $this->merchantType : 'PP';
    }

    public function createPayment(OnlinePaymentRequest $request): OnlinePaymentSession
    {
        if ($request->currency !== 'UAH') {
            throw new \DomainException(CanonicalUiText::get('payment.privat_parts.uah_only'));
        }
        $orderId = $request->orderNumber;
        $created = $this->client->create($orderId, $request->amountMinor, $this->partsCount(), $this->merchantType(), $request->goodsForInstalments(), $request->webhookUrl, $request->returnUrl);

        return new OnlinePaymentSession($orderId, $created['url'], null, ['provider' => 'privat_parts', 'token' => $created['token']]);
    }

    /** Maps the bank's paymentState to the shop's payment status. */
    public static function status(string $paymentState): string
    {
        return match (strtoupper($paymentState)) {
            'SUCCESS' => 'success',
            'LOCKED' => 'hold',
            'FAIL', 'CANCELED', 'REJECTED', 'CLIENT_REJECTED' => 'failure',
            default => 'processing',
        };
    }

    public function pollState(string $providerReference): ?array
    {
        $data = $this->client->state($providerReference);
        $state = (string) ($data['paymentState'] ?? '');
        if ($state === '') {
            return null;
        }

        return ['status' => self::status($state), 'payload' => $data];
    }

    public function refund(string $providerReference, int $amountMinor, string $idempotencyKey): RefundResult
    {
        $data = $this->client->decline($providerReference, $amountMinor);

        return new RefundResult(($data['state'] ?? '') === 'SUCCESS' ? 'refunded' : 'failed', $data);
    }

    public function cancel(string $providerReference): void
    {
        try {
            $this->client->cancel($providerReference);
        } catch (\Throwable) {
            // The bank no longer holds this payment; the order is cancelled in the shop anyway.
        }
    }
}
