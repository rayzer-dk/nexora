<?php

declare(strict_types=1);

namespace Commerce\Modules\ProductPage\Application;

use Commerce\Core\Extension\ExtensionProviderSinkInterface;

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
        $this->registry->register($provider);
    }
}
