<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Infrastructure;

use Commerce\Modules\Storefront\Projection\StorefrontFacetProjectionStore;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Any saved change in the admin area drops the stored storefront catalogue answers, so the shop never shows old data. */
final readonly class CatalogCacheInvalidationSubscriber implements EventSubscriberInterface
{
    public function __construct(private StorefrontCacheVersion $version, private StorefrontFacetProjectionStore $facets)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -80]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (!in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        $route = (string) $request->attributes->get('_route', '');
        if (!str_starts_with($route, 'admin_') || in_array($route, ['admin_login', 'admin_logout', 'admin_login_forgot', 'admin_login_recover'], true)) {
            return;
        }
        if ($event->getResponse()->getStatusCode() >= 400) {
            return;
        }
        $this->version->bump();
        $this->facets->clear();
    }
}
