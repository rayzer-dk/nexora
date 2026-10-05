<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Bots;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** First thing on every request: a blocked crawler gets a bare 403 and costs the shop almost nothing. */
final readonly class BotProtectionSubscriber
{
    /** Payment and scheduler callbacks come from services, not browsers. */
    private const OPEN_PREFIXES = ['/webhooks/', '/cron/', '/health', '/robots.txt'];

    public function __construct(private BotProtection $bots)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4096)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        foreach (self::OPEN_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }
        $reason = $this->bots->verdict((string) $request->headers->get('User-Agent', ''), (string) $request->getClientIp());
        if ($reason === null) {
            return;
        }
        $this->bots->count($reason);
        $event->setResponse(new Response('Forbidden', Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']));
    }

    /** A missing page is the footprint of a scanner: count it per client, outside the files a browser asks for by itself. */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -64)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getResponse()->getStatusCode() !== Response::HTTP_NOT_FOUND) {
            return;
        }
        $request = $event->getRequest();
        if (!in_array($request->getMethod(), ['GET', 'HEAD', 'POST'], true)) {
            return;
        }
        $path = $request->getPathInfo();
        if (preg_match('~^/(media|build|assets)/|\.(?:png|jpe?g|gif|webp|avif|svg|ico|css|js|map|woff2?|ttf|webmanifest)$~i', $path) === 1) {
            return;
        }
        $this->bots->recordNotFound((string) $request->getClientIp(), (string) $request->headers->get('User-Agent', ''));
    }
}
