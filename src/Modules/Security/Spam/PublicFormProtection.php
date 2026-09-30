<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Spam;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * One anti-abuse gate for anonymous storefront forms (contact, callback, price request,
 * newsletter, back-in-stock): honeypot + render-time check + per-client rate limit.
 * Pair it with templates/components/spam_fields.html.twig in the form markup.
 */
final class PublicFormProtection
{
    public function __construct(
        private readonly PublicFormSpamGuard $guard,
        #[Autowire(service: 'limiter.public_form')] private readonly RateLimiterFactoryInterface $publicFormLimiter,
    ) {
    }

    /** True when the request may be processed. */
    public function allow(Request $request, string $scope): bool
    {
        $payload = 0;
        foreach ($request->request->all() as $value) {
            $payload += is_scalar($value) ? strlen((string) $value) : 0;
        }

        $result = $this->guard->check(
            (string) $request->request->get('_website', ''),
            (int) $request->request->get('_rendered_at', 0),
            time(),
            $payload,
        );
        if (!$result->allowed) {
            return false;
        }

        $key = $scope . ':' . ($request->getClientIp() ?? 'unknown');

        return $this->publicFormLimiter->create($key)->consume(1)->isAccepted();
    }
}
