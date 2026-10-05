<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Bots;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
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
}
