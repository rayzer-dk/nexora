<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Application;

use Commerce\Core\Extension\ExtensionProviderSinkInterface;
use Commerce\Modules\Ai\Contract\TranslationProviderInterface;

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
        if (!$provider instanceof TranslationProviderInterface) {
            throw new \InvalidArgumentException('provider.translation expects TranslationProviderInterface.');
        }
        $this->registry->register($provider);
    }
}
