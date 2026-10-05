<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Http;

use Commerce\Modules\Payment\Application\PaymentLifecycleService;
use Commerce\Modules\Payment\Provider\PayPal\PayPalClient;
use Commerce\Modules\Security\Webhook\WebhookReplayGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Backup for the capture made on return: confirms or reverses a PayPal payment when the buyer closed the tab. */
final readonly class PayPalWebhookController
{
    public function __construct(
        private PayPalClient $client,
        private PaymentLifecycleService $lifecycle,
        private WebhookReplayGuard $replays,
    ) {}

    #[Route('/webhooks/payments/paypal', name: 'payment_webhook_paypal', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $headers = [];
        foreach (['paypal-auth-algo', 'paypal-cert-url', 'paypal-transmission-id', 'paypal-transmission-sig', 'paypal-transmission-time'] as $name) {
            $headers[$name] = (string) $request->headers->get($name, '');
        }
        if (!$this->client->verifyWebhook($headers, $raw)) {
            return new JsonResponse(['ok' => false], 401);
        }
        try {
            $event = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return new JsonResponse(['ok' => false], 400);
        }
        $type = (string) ($event['event_type'] ?? '');
        $resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        $reference = trim((string) ($resource['supplementary_data']['related_ids']['order_id'] ?? ''));
        $status = match ($type) {
            'PAYMENT.CAPTURE.COMPLETED' => 'success',
            'PAYMENT.CAPTURE.DENIED' => 'failure',
            default => '',
        };
        if ($reference === '' || $status === '') {
            return new JsonResponse(['ok' => true, 'ignored' => true]);
        }
        $fingerprint = hash('sha256', (string) ($event['id'] ?? $raw));
        if (!$this->replays->claim('paypal', $fingerprint)) {
            return new JsonResponse(['ok' => true, 'duplicate' => true]);
        }
        try {
            $this->lifecycle->applyProviderStatus(
                'paypal', $reference, $status, isset($resource['update_time']) ? (string) $resource['update_time'] : null,
                (int) round(((float) ($resource['amount']['value'] ?? 0)) * 100), strtoupper((string) ($resource['amount']['currency_code'] ?? '')), null, $resource,
            );
        } catch (\RuntimeException) {
            $this->replays->release('paypal', $fingerprint);

            return new JsonResponse(['ok' => false, 'error' => 'payment_state_rejected'], 409);
        } catch (\Throwable $e) {
            $this->replays->release('paypal', $fingerprint);
            throw $e;
        }

        return new JsonResponse(['ok' => true]);
    }
}
