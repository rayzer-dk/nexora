<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ExtensionProviderSinks
{
    /** @param iterable<ExtensionProviderSinkInterface> $sinks */
    public function __construct(#[AutowireIterator('commerce.extension_provider_sink')] private iterable $sinks = [])
    {
    }

    public function accept(string $capability, object $provider): void
    {
        foreach ($this->sinks as $sink) {
            if ($sink->capability() === $capability) {
                $sink->accept($provider);

                return;
            }
        }
        throw new \LogicException(\Commerce\Core\I18n\CanonicalUiText::get('extension.sdk.provider_capability_unknown') . $capability);
    }
}
