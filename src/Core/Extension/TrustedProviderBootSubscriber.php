<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Providers of signed modules (payment, shipping, product blocks, AI, translation) exist only after the module has
 * booted and registered them. Modules boot lazily (own route, subscribed event, cron), which is too late for a checkout
 * or product page that lists providers. When an active module declares a provider capability, it boots before the
 * request is handled; stores without such a module pay nothing (the manifests are already loaded for UI slots).
 */
final class TrustedProviderBootSubscriber
{
    public function __construct(private readonly ExtensionContributionRegistry $contributions, private readonly TrustedExtensionRuntimeLoader $loader)
    {
    }

    #[AsEventListener(event: 'kernel.request', priority: -16)]
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest() && self::needsBoot($this->contributions->activeManifests())) {
            $this->loader->bootActive();
        }
    }

    /** @param list<array<string,mixed>> $manifests */
    public static function needsBoot(array $manifests): bool
    {
        foreach ($manifests as $manifest) {
            if ((string) ($manifest['execution'] ?? '') !== 'trusted_release') {
                continue;
            }
            foreach ((array) ($manifest['capabilities'] ?? []) as $capability) {
                if (is_string($capability) && str_starts_with($capability, 'provider.')) {
                    return true;
                }
            }
        }

        return false;
    }
}
