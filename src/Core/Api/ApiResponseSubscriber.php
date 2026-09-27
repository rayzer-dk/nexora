<?php

declare(strict_types=1);

namespace Commerce\Core\Api;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiResponseSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -50]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !str_starts_with($event->getRequest()->getPathInfo(), '/api/v1')) {
            return;
        }
        $response = $event->getResponse();
        $response->headers->set('X-Commerce-API-Version', ApiContractVersion::V1);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Vary', trim(($response->headers->get('Vary') ?: '') . ', Accept-Language', ', '));
    }
}
