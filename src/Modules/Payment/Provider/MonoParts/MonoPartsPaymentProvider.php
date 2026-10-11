<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Provider\MonoParts;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Payment\Contract\OnlinePaymentProviderInterface;
use Commerce\Modules\Payment\Contract\PollablePaymentProviderInterface;
use Commerce\Modules\Payment\Domain\OnlinePaymentRequest;
use Commerce\Modules\Payment\Domain\OnlinePaymentSession;
use Commerce\Modules\Payment\Domain\PaymentMethod;
use Commerce\Modules\Payment\Domain\RefundResult;

final readonly class MonoPartsPaymentProvider implements OnlinePaymentProviderInterface, PollablePaymentProviderInterface
{
    public function __construct(private MonoPartsClient $client, private bool $isEnabled, private string $storeId, private string $secret, private string $parts, private bool $autoConfirm)
    {
    }

    public function method(): PaymentMethod
    {
        return new PaymentMethod('mono_parts', CanonicalUiText::get('payment.mono_parts.name'), true);
    }

    public function enabled(): bool
    {
        return $this->isEnabled && $this->storeId !== '' && $this->secret !== '';
    }

    public function autoConfirm(): bool
    {
        return $this->autoConfirm;
    }

    public function client(): MonoPartsClient
    {
        return $this->client;
    }

    /** @return list<int> the numbers of parts the shop offers (from the contract with the bank) */
    public function parts(): array
    {
        $list = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,;]+/', $this->parts) ?: []), static fn (int $n): bool => $n >= 2 && $n <= 24)));
        sort($list);

        return $list === [] ? [3, 4, 6, 10] : $list;
    }

    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 10 && $digits[0] === '0') {
            $digits = '38' . $digits;
        } elseif (strlen($digits) === 9) {
            $digits = '380' . $digits;
        }

        return strlen($digits) === 12 && str_starts_with($digits, '380') ? '+' . $digits : '';
    }

    /** @return array<string,mixed> the body of /api/order/create */
    public function orderBody(OnlinePaymentRequest $request, string $phone): array
    {
        $products = [];
        foreach ($request->goodsForInstalments() as $line) {
            // One line per product with its line total, so the list adds up to the amount whatever "sum" means per unit.
            $name = $line['count'] > 1 ? $line['name'] . ' x ' . $line['count'] : $line['name'];
            $products[] = ['name' => mb_substr($name, 0, 100), 'count' => 1, 'sum' => round($line['count'] * $line['price_minor'] / 100, 2)];
        }

        return [
            'store_order_id' => $request->orderPublicId,
            'client_phone' => $phone,
            'total_sum' => round($request->amountMinor / 100, 2),
            'invoice' => ['number' => mb_substr($request->orderNumber, 0, 64), 'date' => gmdate('Y-m-d'), 'source' => 'INTERNET'],
            'available_programs' => [['type' => 'payment_installments', 'available_parts_count' => $this->parts()]],
            'products' => $products,
            'result_callback' => $request->webhookUrl,
        ];
    }

    public function createPayment(OnlinePaymentRequest $request): OnlinePaymentSession
    {
        if ($request->currency !== 'UAH') {
            throw new \DomainException(CanonicalUiText::get('payment.mono_parts.uah_only'));
        }
        $phone = self::normalizePhone($request->customerPhone);
        if ($phone === '') {
            throw new \DomainException(CanonicalUiText::get('payment.mono_parts.phone_required'));
        }
        $data = $this->client->createOrder($this->orderBody($request, $phone));
        $orderId = trim((string) ($data['order_id'] ?? ''));
        if ($orderId === '') {
            throw new \RuntimeException('monobank instalments returned no order_id');
        }

        // The shopper confirms in the monobank app; the page they come back to explains it and follows the state.
        return new OnlinePaymentSession($orderId, $request->returnUrl, null, ['provider' => 'mono_parts', 'phone' => $phone]);
    }

    /** Maps the bank's state and sub-state to the shop's payment status. */
    public static function status(string $state, string $subState): string
    {
        return match (true) {
            $state === 'SUCCESS' && $subState === 'RETURNED' => 'reversed',
            $state === 'SUCCESS' => 'success',
            $state === 'FAIL' => 'failure',
            $state === 'IN_PROCESS' && $subState === 'WAITING_FOR_STORE_CONFIRM' => 'hold',
            default => 'processing',
        };
    }

    public function pollState(string $providerReference): ?array
    {
        $data = $this->client->state($providerReference);
        $state = (string) ($data['state'] ?? '');
        if ($state === '') {
            return null;
        }

        return ['status' => self::status($state, (string) ($data['order_sub_state'] ?? '')), 'payload' => $data];
    }

    public function refund(string $providerReference, int $amountMinor, string $idempotencyKey): RefundResult
    {
        throw new \DomainException(CanonicalUiText::get('payment.mono_parts.refund_in_bank'));
    }

    public function cancel(string $providerReference): void
    {
        try {
            $this->client->reject($providerReference);
        } catch (\Throwable) {
            // Not rejectable any more (already paid or closed): the order is cancelled in the shop anyway and the bank side is settled in the bank.
        }
    }
}
