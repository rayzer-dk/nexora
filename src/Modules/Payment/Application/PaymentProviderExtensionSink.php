<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Application;

use Commerce\Core\Extension\ExtensionProviderSinkInterface;
use Commerce\Modules\Payment\Contract\PaymentProviderInterface;

final readonly class PaymentProviderExtensionSink implements ExtensionProviderSinkInterface
{
    public function __construct(private PaymentProviderRegistry $registry)
    {
    }

    public function capability(): string
    {
        return 'provider.payment';
    }

    public function accept(object $provider): void
    {
        if (!$provider instanceof PaymentProviderInterface) {
            throw new \InvalidArgumentException('provider.payment expects PaymentProviderInterface.');
        }
        $this->registry->register($provider);
    }
}
