<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Http;

use Commerce\Modules\Payment\Application\PaymentLifecycleService;
use Commerce\Modules\Payment\Provider\Stripe\StripeClient;
use Commerce\Modules\Security\Webhook\WebhookReplayGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class StripeWebhookController
{
    public function __construct(
        private StripeClient $client,
        private PaymentLifecycleService $lifecycle,
        private WebhookReplayGuard $replays,
    ) {}

    #[Route('/webhooks/payments/stripe', name: 'payment_webhook_stripe', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        if (!$this->client->verify($raw, (string) $request->headers->get('Stripe-Signature', ''))) {
            return new JsonResponse(['ok' => false], 401);
        }
        try {
            $event = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return new JsonResponse(['ok' => false], 400);
        }
        $type = (string) ($event['type'] ?? '');
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
        $reference = trim((string) ($object['id'] ?? ''));
        $status = match (true) {
            $type === 'checkout.session.completed' && ($object['payment_status'] ?? '') === 'paid',
            $type === 'checkout.session.async_payment_succeeded' => 'success',
            $type === 'checkout.session.expired' => 'expired',
            $type === 'checkout.session.async_payment_failed' => 'failure',
            default => '',
        };
        if ($reference === '' || $status === '') {
            return new JsonResponse(['ok' => true, 'ignored' => true]);
        }
        $fingerprint = hash('sha256', (string) ($event['id'] ?? $raw));
        if (!$this->replays->claim('stripe', $fingerprint)) {
            return new JsonResponse(['ok' => true, 'duplicate' => true]);
        }
        try {
            $this->lifecycle->applyProviderStatus(
                'stripe', $reference, $status, isset($event['created']) ? gmdate('c', (int) $event['created']) : null,
                (int) ($object['amount_total'] ?? 0), strtoupper((string) ($object['currency'] ?? '')), null, $object,
            );
        } catch (\RuntimeException) {
            $this->replays->release('stripe', $fingerprint);

            return new JsonResponse(['ok' => false, 'error' => 'payment_state_rejected'], 409);
        } catch (\Throwable $e) {
            $this->replays->release('stripe', $fingerprint);
            throw $e;
        }

        return new JsonResponse(['ok' => true]);
    }
}
