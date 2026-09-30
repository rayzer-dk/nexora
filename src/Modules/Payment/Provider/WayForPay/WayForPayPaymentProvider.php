<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Provider\WayForPay;

use Commerce\Modules\Payment\Contract\OnlinePaymentProviderInterface;
use Commerce\Modules\Payment\Domain\OnlinePaymentRequest;
use Commerce\Modules\Payment\Domain\OnlinePaymentSession;
use Commerce\Modules\Payment\Domain\PaymentMethod;
use Commerce\Modules\Payment\Domain\RefundResult;

final readonly class WayForPayPaymentProvider implements OnlinePaymentProviderInterface
{
    private const CURRENCIES = ['UAH', 'USD', 'EUR'];

    public function __construct(private WayForPayClient $client, private bool $isEnabled) {}

    public function method(): PaymentMethod
    {
        return new PaymentMethod('wayforpay', \Commerce\Core\I18n\CanonicalUiText::get('payment.method.wayforpay'), true, false);
    }

    public function enabled(): bool { return $this->isEnabled && $this->client->configured(); }

    public function createPayment(OnlinePaymentRequest $request): OnlinePaymentSession
    {
        if (!in_array($request->currency, self::CURRENCIES, true)) {
            throw new \DomainException('wayforpay_currency_unsupported');
        }
        $amount = number_format($request->amountMinor / 100, 2, '.', '');
        $name = 'Order ' . $request->orderNumber;
        $orderDate = time();
        $signature = $this->client->sign([
            $this->client->merchantAccount(), $this->client->domain(), $request->orderNumber, $orderDate,
            $amount, $request->currency, $name, 1, $amount,
        ]);
        $data = $this->client->request([
            'transactionType' => 'CREATE_INVOICE',
            'apiVersion' => 1,
            'merchantAccount' => $this->client->merchantAccount(),
            'merchantDomainName' => $this->client->domain(),
            'merchantSignature' => $signature,
            'orderReference' => $request->orderNumber,
            'orderDate' => $orderDate,
            'amount' => $amount,
            'currency' => $request->currency,
            'productName' => [$name],
            'productPrice' => [$amount],
            'productCount' => [1],
            'serviceUrl' => $request->webhookUrl,
            'returnUrl' => $request->returnUrl,
            'orderTimeout' => 3600,
            'language' => 'UA',
        ]);
        $url = trim((string)($data['invoiceUrl'] ?? ''));
        if ($url === '' || !str_starts_with($url, 'https://')) {
            throw new \RuntimeException('wayforpay_no_invoice_url');
        }
        return new OnlinePaymentSession($request->orderNumber, $url, null, ['provider' => 'wayforpay']);
    }

    public function refund(string $providerReference, int $amountMinor, string $idempotencyKey): RefundResult
    {
        $amount = number_format($amountMinor / 100, 2, '.', '');
        $currency = 'UAH';
        $data = $this->client->request([
            'transactionType' => 'REFUND',
            'apiVersion' => 1,
            'merchantAccount' => $this->client->merchantAccount(),
            'orderReference' => $providerReference,
            'amount' => $amount,
            'currency' => $currency,
            'comment' => 'Refund',
            'merchantSignature' => $this->client->sign([$this->client->merchantAccount(), $providerReference, $amount, $currency]),
        ]);
        $status = strtolower((string)($data['transactionStatus'] ?? ''));
        return new RefundResult(in_array($status, ['refunded', 'voided'], true) ? 'refunded' : ($status === 'declined' ? 'failed' : 'processing'), $data);
    }

    public function cancel(string $providerReference): void
    {
        // WayForPay invoices expire by orderTimeout; nothing to revoke.
    }
}
