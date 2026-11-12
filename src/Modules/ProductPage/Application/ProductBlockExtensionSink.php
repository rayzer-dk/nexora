<?php

declare(strict_types=1);

namespace Commerce\Modules\ProductPage\Application;

use Commerce\Core\Extension\ExtensionProviderSinkInterface;
use Commerce\Modules\ProductPage\Contract\ProductBlockProviderInterface;

final readonly class ProductBlockExtensionSink implements ExtensionProviderSinkInterface
{
    public function __construct(private ProductBlockRegistry $registry)
    {
    }

    public function capability(): string
    {
        return 'provider.product_block';
    }

    public function accept(object $provider): void
    {
        if (!$provider instanceof ProductBlockProviderInterface) {
            throw new \InvalidArgumentException('provider.product_block expects ProductBlockProviderInterface.');
        }
        $this->registry->register($provider);
    }
}
