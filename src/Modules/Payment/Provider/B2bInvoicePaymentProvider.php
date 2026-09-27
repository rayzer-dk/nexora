<?php

declare(strict_types=1);
namespace Commerce\Modules\Payment\Provider;
use Commerce\Modules\Payment\Contract\PaymentProviderInterface;
use Commerce\Modules\Payment\Domain\PaymentMethod;
final class B2bInvoicePaymentProvider implements PaymentProviderInterface
{
    public function method(): PaymentMethod { return new PaymentMethod('b2b_invoice',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.provider.b2binvoicepaymentprovider.oplata_za_rakhunkom_vidstrochka'),false,false); }
    public function enabled(): bool { return true; }
}
