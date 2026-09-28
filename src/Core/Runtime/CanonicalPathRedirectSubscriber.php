<?php

declare(strict_types=1);

namespace Commerce\Core\Runtime;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * One URL per page: /laptops/, /index.php and /index.php/laptops answered 200 with the same
 * content as /laptops. They are permanently redirected to the canonical path (query kept).
 * Runs before routing so every storefront route benefits.
 */
final class CanonicalPathRedirectSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 256]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return;
        }
        $uri = (string) $request->server->get('REQUEST_URI', '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $canonical = $path;
        if ($canonical === '/index.php' || str_starts_with($canonical, '/index.php/')) {
            $canonical = substr($canonical, strlen('/index.php')) ?: '/';
        }
        if ($canonical !== '/' && str_ends_with($canonical, '/')) {
            $canonical = rtrim($canonical, '/') ?: '/';
        }
        if ($canonical === $path || str_contains($canonical, '//')) {
            return;
        }
        $query = (string) (parse_url($uri, PHP_URL_QUERY) ?? '');
        $event->setResponse(new RedirectResponse($canonical . ($query !== '' ? '?' . $query : ''), 301));
    }
}
