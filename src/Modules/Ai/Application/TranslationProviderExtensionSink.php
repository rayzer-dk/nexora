<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Application;

use Commerce\Core\Extension\ExtensionProviderSinkInterface;

final readonly class TranslationProviderExtensionSink implements ExtensionProviderSinkInterface
{
    public function __construct(private TranslationProviderRegistry $registry)
    {
    }

    public function capability(): string
    {
        return 'provider.translation';
    }

    public function accept(object $provider): void
    {
        $this->registry->register($provider);
    }
}
