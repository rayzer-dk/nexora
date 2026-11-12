<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Application;

use Commerce\Core\Extension\ExtensionProviderSinkInterface;
use Commerce\Modules\Shipping\Contract\DeliveryProviderInterface;

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
        if (!$provider instanceof DeliveryProviderInterface) {
            throw new \InvalidArgumentException('provider.shipping expects DeliveryProviderInterface.');
        }
        $this->registry->register($provider);
    }
}
