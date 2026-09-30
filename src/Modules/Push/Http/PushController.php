<?php

declare(strict_types=1);

namespace Commerce\Modules\Push\Http;

use Commerce\Modules\Push\Application\PushSettings;
use Commerce\Modules\Push\Application\PushSubscriptionService;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/** Storefront visitors opt in with an explicit click; the browser adds its own permission prompt. */
final class PushController
{
    public function __construct(
        private readonly PushSettings $settings,
        private readonly PushSubscriptionService $subscriptions,
        private readonly StorefrontContextResolver $contexts,
        #[Autowire(service: 'limiter.public_form')] private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/push/subscribe', name: 'storefront_push_subscribe', methods: ['POST'], priority: 950)]
    public function subscribe(Request $request): JsonResponse
    {
        $context = $this->guard($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !is_array($data['keys'] ?? null)) {
            return new JsonResponse(['ok' => false], 400);
        }
        try {
            $this->subscriptions->subscribe($context->storeId, 'storefront', (string) ($data['endpoint'] ?? ''), (string) ($data['keys']['p256dh'] ?? ''), (string) ($data['keys']['auth'] ?? ''), $context->locale);
        } catch (\Throwable) {
            return new JsonResponse(['ok' => false], 422);
        }

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/push/unsubscribe', name: 'storefront_push_unsubscribe', methods: ['POST'], priority: 950)]
    public function unsubscribe(Request $request): JsonResponse
    {
        $context = $this->guard($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $data = json_decode($request->getContent(), true);
        if (is_array($data) && is_string($data['endpoint'] ?? null)) {
            $this->subscriptions->unsubscribe($context->storeId, $data['endpoint']);
        }

        return new JsonResponse(['ok' => true]);
    }

    private function guard(Request $request): \Commerce\Modules\Storefront\Domain\StorefrontContext|JsonResponse
    {
        $origin = (string) $request->headers->get('Origin', '');
        if ($origin !== '' && $origin !== $request->getSchemeAndHttpHost()) {
            return new JsonResponse(['ok' => false], 403);
        }
        if (!str_contains((string) $request->headers->get('Content-Type', ''), 'application/json')) {
            return new JsonResponse(['ok' => false], 415);
        }
        if (!$this->limiter->create('push:' . ($request->getClientIp() ?? 'unknown'))->consume(1)->isAccepted()) {
            return new JsonResponse(['ok' => false], 429);
        }
        $context = $this->contexts->resolve($request);
        if (!$this->settings->get($context->storeId)['enabled']) {
            return new JsonResponse(['ok' => false], 404);
        }

        return $context;
    }
}
