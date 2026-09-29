<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

final class SecurityHeadersSubscriber
{
    #[AsEventListener(event: 'kernel.request', priority: 32)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->attributes->set('_csp_nonce', base64_encode(random_bytes(18)));
    }

    #[AsEventListener(event: 'kernel.response', priority: -64)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $nonce = (string) $request->attributes->get('_csp_nonce', '');
        $csp = implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "script-src 'self' 'nonce-{$nonce}' https://accounts.google.com https://challenges.cloudflare.com",
            "style-src 'self' 'unsafe-inline' https://accounts.google.com",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            "connect-src 'self' https://accounts.google.com https://challenges.cloudflare.com",
            "frame-src 'self' https://accounts.google.com https://challenges.cloudflare.com",
            "worker-src 'self' blob:",
        ]);

        $headers = $response->headers;
        $headers->set('Content-Security-Policy', $csp);
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), usb=(), browsing-topics=()');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');
        $headers->set('Cross-Origin-Resource-Policy', 'same-site');

        // Pages that carry a session, CSRF token or CSP nonce must never be stored by a shared cache
        // (reverse proxy, LiteSpeed, CDN); a cached login form makes every sign-in fail with a stale token.
        $path = $request->getPathInfo();
        foreach (['/admin', '/account', '/checkout', '/cart', '/setup.php', '/install'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $headers->set('Cache-Control', 'no-store, private');
                $headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
                break;
            }
        }

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }
}
