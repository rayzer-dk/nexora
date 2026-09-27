<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Provider;

use Commerce\Modules\Payment\Contract\PaymentProviderInterface;
use Commerce\Modules\Payment\Domain\PaymentMethod;

final class BankTransferPaymentProvider implements PaymentProviderInterface
{
    public function method(): PaymentMethod { return new PaymentMethod('bank_transfer', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.provider.banktransferpaymentprovider.bankivskyi_perekaz'), false, false); }
    public function enabled(): bool { return true; }
}
