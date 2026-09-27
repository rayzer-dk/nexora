<?php

declare(strict_types=1);

namespace Commerce\Core\Runtime;

use Commerce\Core\Event\DomainEventOutboxWorker;
use Commerce\Modules\Notification\Application\NotificationOutboxWorker;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Opportunistically drains a tiny amount of work only after a request actually
 * created deferred work. Core success never depends on this subscriber.
 */
final readonly class DeferredWorkTerminateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private DeferredWorkSignal $signal,
        private DomainEventOutboxWorker $events,
        private NotificationOutboxWorker $notifications,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => ['onTerminate', -128]];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->signal->pending()) {
            return;
        }
        $this->signal->clear();
        try {
            $this->events->run(8);
        } catch (Throwable) {
            return;
        }
        try {
            $this->notifications->run(8);
        } catch (Throwable) {
            // The durable queues retain work for the CLI worker/retry path.
        }
    }
}
