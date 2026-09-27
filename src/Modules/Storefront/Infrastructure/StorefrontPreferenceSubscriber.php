<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Infrastructure;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class StorefrontPreferenceSubscriber implements EventSubscriberInterface
{
    public function __construct(private StorefrontContextResolver $contexts)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -20]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $request = $event->getRequest();
        if (str_starts_with((string)$request->attributes->get('_route', ''), 'admin_')) { return; }
        if (!$request->query->has('lang') && !$request->query->has('currency') && !$request->query->has('market')) { return; }

        try {
            $context = $this->contexts->resolve($request);
            $response = $event->getResponse();
            $expires = new \DateTimeImmutable('+180 days');
            if ($request->query->has('lang')) {
                $response->headers->setCookie(Cookie::create('store_locale', $context->locale, $expires, '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));
            }
            if ($request->query->has('currency')) {
                $response->headers->setCookie(Cookie::create('store_currency', $context->currency, $expires, '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));
            }
            if ($request->query->has('market')) {
                $marketCode = trim((string)$request->query->get('market', ''));
                if ($marketCode !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $marketCode) === 1) {
                    $response->headers->setCookie(Cookie::create('store_market', $marketCode, $expires, '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));
                }
            }
        } catch (\Throwable) {
            // Invalid or unavailable preferences are ignored; the response remains usable.
        }
    }
}
