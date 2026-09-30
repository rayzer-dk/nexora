<?php

declare(strict_types=1);

namespace Commerce\Modules\Analytics\Visit;

use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/** Runs after the response is sent, so statistics never slow a page down and never break it. */
final class VisitTrackingSubscriber
{
    public function __construct(private readonly VisitTracker $tracker, private readonly StorefrontContextResolver $contexts)
    {
    }

    #[AsEventListener(event: 'kernel.terminate')]
    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        try {
            if (!$this->tracker->countable($request, $response->getStatusCode(), (string) $response->headers->get('Content-Type', ''))) {
                return;
            }
            $storeId = $this->contexts->resolve($request)->storeId;
            if (!$this->tracker->settings($storeId)['enabled']) {
                return;
            }
            $this->tracker->record($request, $storeId);
        } catch (\Throwable) {
            // statistics are best effort
        }
    }
}
