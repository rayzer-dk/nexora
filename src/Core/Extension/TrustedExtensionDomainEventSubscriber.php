<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

use Commerce\Core\Event\DomainEventSubscriberInterface;
use Commerce\Core\Event\StoredDomainEvent;

final readonly class TrustedExtensionDomainEventSubscriber implements DomainEventSubscriberInterface
{
    public function __construct(
        private ExtensionContributionRegistry $contributions,
        private TrustedExtensionRuntimeLoader $loader,
        private TrustedExtensionRuntimeRegistry $runtime,
    ) {
    }

    public function subscriberId(): string
    {
        return 'core.trusted_extensions';
    }

    public function subscribedEvents(): array
    {
        $events = [];
        foreach ($this->contributions->activeManifests() as $manifest) {
            if ((string) ($manifest['execution'] ?? '') !== 'trusted_release') {
                continue;
            }
            foreach ((array) ($manifest['events'] ?? []) as $event) {
                if (is_string($event) && $event !== '') {
                    $events[$event] = true;
                }
            }
        }
        return array_keys($events);
    }

    public function handle(StoredDomainEvent $event): void
    {
        $this->loader->bootActive();
        $this->runtime->dispatchEvent($event);
    }
}
