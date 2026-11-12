<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Application;

use Commerce\Core\Extension\ExtensionProviderSinkInterface;

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
        $this->registry->register($provider);
    }
}
