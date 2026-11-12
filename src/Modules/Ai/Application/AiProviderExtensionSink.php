<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Application;

use Commerce\Core\Extension\ExtensionProviderSinkInterface;

final readonly class AiProviderExtensionSink implements ExtensionProviderSinkInterface
{
    public function __construct(private AiProviderRegistry $registry)
    {
    }

    public function capability(): string
    {
        return 'provider.ai';
    }

    public function accept(object $provider): void
    {
        $this->registry->register($provider);
    }
}
