<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Provider;

use Commerce\Modules\Payment\Contract\PaymentProviderInterface;
use Commerce\Modules\Payment\Domain\PaymentMethod;

final class CashOnDeliveryPaymentProvider implements PaymentProviderInterface
{
    public function method(): PaymentMethod
    {
        return new PaymentMethod('cash_on_delivery', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.provider.cashondeliverypaymentprovider.oplata_pry_otrymanni'), false, true);
    }

    public function enabled(): bool { return true; }
}
