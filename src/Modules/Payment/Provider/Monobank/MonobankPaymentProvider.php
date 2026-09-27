<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Provider\Monobank;

use Commerce\Modules\Payment\Contract\OnlinePaymentProviderInterface;
use Commerce\Modules\Payment\Domain\OnlinePaymentRequest;
use Commerce\Modules\Payment\Domain\OnlinePaymentSession;
use Commerce\Modules\Payment\Domain\PaymentMethod;
use Commerce\Modules\Payment\Domain\RefundResult;

final readonly class MonobankPaymentProvider implements OnlinePaymentProviderInterface
{
    public function __construct(private MonobankClient $client, private bool $isEnabled, private string $token) {}
    public function method(): PaymentMethod { return new PaymentMethod('monobank',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.provider.monobank.monobankpaymentprovider.kartka_apple_pay_google_pay'),true,false); }
    public function enabled(): bool { return $this->isEnabled && $this->token!==''; }

    public function createPayment(OnlinePaymentRequest $request): OnlinePaymentSession
    {
        if($request->currency!=='UAH') throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.provider.monobank.monobankpaymentprovider.mono_acquiring_u_bazovii_konfihuratsii_dostupnyi_lys'));
        $data=$this->client->createInvoice([
            'amount'=>$request->amountMinor,
            'ccy'=>980,
            'redirectUrl'=>$request->returnUrl,
            'webHookUrl'=>$request->webhookUrl,
            'validity'=>3600,
            'paymentType'=>'debit',
        ]);
        $invoice=trim((string)($data['invoiceId']??'')); $url=trim((string)($data['pageUrl']??''));
        if($invoice==='' || $url==='') throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.provider.monobank.monobankpaymentprovider.mono_acquiring_ne_povernuv_invoiceid_pageurl'));
        return new OnlinePaymentSession($invoice,$url,isset($data['appUrl'])?(string)$data['appUrl']:null,['provider'=>'monobank']);
    }

    public function refund(string $providerReference,int $amountMinor,string $idempotencyKey): RefundResult
    {
        $data=$this->client->refund($providerReference,$amountMinor,$idempotencyKey);
        return new RefundResult((string)($data['status']??'processing'),$data);
    }

    public function cancel(string $providerReference): void { $this->client->removeInvoice($providerReference); }
}
