<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Http;

use Commerce\Modules\Payment\Application\InstalmentStateService;
use Commerce\Modules\Payment\Application\PaymentProviderRegistry;
use Commerce\Modules\Payment\Provider\MonoParts\MonoPartsPaymentProvider;
use Commerce\Modules\Security\Webhook\WebhookReplayGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** monobank sends {order_id, state, order_sub_state} when the shopper has agreed in the app, or when the order failed; the body is signed with the store secret. */
final readonly class MonoPartsWebhookController
{
    public function __construct(private PaymentProviderRegistry $providers, private InstalmentStateService $state, private WebhookReplayGuard $replays)
    {
    }

    #[Route('/webhooks/payments/mono_parts', name: 'payment_webhook_mono_parts', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $provider = $this->providers->require('mono_parts');
        if (!$provider instanceof MonoPartsPaymentProvider) {
            return new JsonResponse(['ok' => false], 404);
        }
        $raw = $request->getContent();
        $signature = (string) $request->headers->get('signature', '');
        if ($signature === '' || !$provider->client()->verify($raw, $signature)) {
            return new JsonResponse(['ok' => false], 401);
        }
        $data = json_decode($raw, true);
        $orderId = is_array($data) ? trim((string) ($data['order_id'] ?? '')) : '';
        $state = is_array($data) ? trim((string) ($data['state'] ?? '')) : '';
        if ($orderId === '' || $state === '') {
            return new JsonResponse(['ok' => false], 400);
        }
        $fingerprint = hash('sha256', $raw);
        if (!$this->replays->claim('mono_parts', $fingerprint)) {
            return new JsonResponse(['ok' => true, 'duplicate' => true]);
        }
        try {
            $this->state->apply('mono_parts', $orderId, MonoPartsPaymentProvider::status($state, (string) ($data['order_sub_state'] ?? '')), $data);
        } catch (\Throwable $e) {
            $this->replays->release('mono_parts', $fingerprint);

            return new JsonResponse(['ok' => false, 'error' => 'payment_state_rejected'], $e instanceof \RuntimeException ? 409 : 500);
        }

        return new JsonResponse(['ok' => true]);
    }
}
