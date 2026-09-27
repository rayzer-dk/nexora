<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Contract;

use Commerce\Modules\Payment\Domain\PaymentMethod;

interface PaymentProviderInterface
{
    public function method(): PaymentMethod;
    public function enabled(): bool;
}
