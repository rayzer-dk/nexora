<?php

declare(strict_types=1);

namespace Commerce\Modules\Affiliate\EventSubscriber;

use Commerce\Modules\Affiliate\Application\AffiliateService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** A visit with ?ref=CODE leaves a cookie for 30 days (storefront pages only). */
final readonly class AffiliateRequestSubscriber
{
    private const ATTRIBUTE = '_mc_affiliate_code';

    public function __construct(private AffiliateService $affiliates)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 3)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('GET') || str_starts_with($request->getPathInfo(), '/admin') || !$request->query->has('ref')) {
            return;
        }
        $ref = (string) $request->query->get('ref', '');
        try {
            $code = $this->affiliates->visit($ref, strtolower((string) $request->cookies->get(AffiliateService::COOKIE, '')) === strtolower($ref));
        } catch (\Throwable) {
            $code = null;
        }
        if ($code !== null) {
            $request->attributes->set(self::ATTRIBUTE, $code);
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $code = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if (!is_string($code) || !$event->isMainRequest()) {
            return;
        }
        $event->getResponse()->headers->setCookie(Cookie::create(AffiliateService::COOKIE, $code, time() + AffiliateService::DAYS * 86400, '/', null, $event->getRequest()->isSecure(), true, false, Cookie::SAMESITE_LAX));
    }
}
