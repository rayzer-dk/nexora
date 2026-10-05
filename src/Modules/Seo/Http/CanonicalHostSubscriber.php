<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * One address per site: example.com and www.example.com (and http/https) must not all answer with the same page,
 * otherwise search engines split the ranking between duplicates. The address in APP_PUBLIC_URL is the canonical one;
 * the other variant of the same host, and plain http when the site is https, get a permanent redirect to it.
 * Local hosts, IP addresses and machine endpoints are left alone. COMMERCE_CANONICAL_REDIRECT=0 switches it off.
 */
final class CanonicalHostSubscriber
{
    private const SKIP_PREFIXES = ['/health', '/webhooks/', '/cron/', '/.well-known/'];

    public function __construct(private readonly string $publicUrl, private readonly bool $enabled = true)
    {
    }

    #[AsEventListener(event: 'kernel.request', priority: 250)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $target = $this->target($request);
        if ($target !== null) {
            $event->setResponse(new RedirectResponse($target, 301));
        }
    }

    public function target(Request $request): ?string
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }
        $path = $request->getPathInfo();
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return null;
            }
        }
        $public = parse_url($this->publicUrl);
        $host = strtolower((string) ($public['host'] ?? ''));
        $scheme = strtolower((string) ($public['scheme'] ?? 'https'));
        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false || !str_contains($host, '.') || preg_match('/\.(local|test|localhost|invalid|example)$/', $host) === 1) {
            return null;
        }
        $requestHost = strtolower($request->getHost());
        $sameSite = $requestHost === $host || $requestHost === 'www.' . $host || 'www.' . $requestHost === $host;
        if (!$sameSite) {
            return null;
        }
        $secure = $request->isSecure()
            || strtolower((string) $request->headers->get('X-Forwarded-Proto')) === 'https'
            || str_contains(strtolower((string) $request->headers->get('CF-Visitor')), 'https');
        $wrongHost = $requestHost !== $host;
        $wrongScheme = $scheme === 'https' && !$secure;
        if (!$wrongHost && !$wrongScheme) {
            return null;
        }
        $port = isset($public['port']) ? ':' . $public['port'] : '';

        return $scheme . '://' . $host . $port . $request->getRequestUri();
    }
}
