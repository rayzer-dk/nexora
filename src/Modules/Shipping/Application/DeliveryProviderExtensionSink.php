<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Application;

use Commerce\Core\Extension\ExtensionProviderSinkInterface;

final readonly class DeliveryProviderExtensionSink implements ExtensionProviderSinkInterface
{
    public function __construct(private DeliveryProviderRegistry $registry)
    {
    }

    public function capability(): string
    {
        return 'provider.shipping';
    }

    public function accept(object $provider): void
    {
        $this->registry->register($provider);
    }
}
