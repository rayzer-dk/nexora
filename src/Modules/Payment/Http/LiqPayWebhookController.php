<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Http;

use Commerce\Modules\Payment\Application\PaymentLifecycleService;
use Commerce\Modules\Payment\Provider\LiqPay\LiqPayClient;
use Commerce\Modules\Security\Webhook\WebhookReplayGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LiqPayWebhookController
{
    public function __construct(
        private LiqPayClient $client,
        private PaymentLifecycleService $lifecycle,
        private WebhookReplayGuard $replays,
    ) {}

    #[Route('/webhooks/payments/liqpay', name: 'payment_webhook_liqpay', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $data = (string)$request->request->get('data', '');
        $signature = (string)$request->request->get('signature', '');
        if (!$this->client->verify($data, $signature)) {
            return new JsonResponse(['ok' => false], 401);
        }
        $payload = $this->client->decode($data);
        $orderId = trim((string)($payload['order_id'] ?? ''));
        $status = trim((string)($payload['status'] ?? ''));
        if ($orderId === '' || $status === '') {
            return new JsonResponse(['ok' => false], 400);
        }
        $fingerprint = hash('sha256', $data . "\0" . $signature);
        if (!$this->replays->claim('liqpay', $fingerprint)) {
            return new JsonResponse(['ok' => true, 'duplicate' => true]);
        }
        $mapped = self::mapStatus($status);
        $amountMinor = (int)round(((float)($payload['amount'] ?? 0)) * 100);
        $modified = isset($payload['end_date']) && is_numeric($payload['end_date']) ? gmdate('c', (int)((int)$payload['end_date'] / 1000)) : null;
        try {
            $this->lifecycle->applyProviderStatus(
                'liqpay', $orderId, $mapped, $modified, $amountMinor,
                strtoupper((string)($payload['currency'] ?? '')),
                isset($payload['err_description']) ? mb_substr((string)$payload['err_description'], 0, 250) : null,
                $payload,
            );
        } catch (\RuntimeException) {
            $this->replays->release('liqpay', $fingerprint);
            return new JsonResponse(['ok' => false, 'error' => 'payment_state_rejected'], 409);
        } catch (\Throwable $e) {
            $this->replays->release('liqpay', $fingerprint);
            throw $e;
        }
        return new JsonResponse(['ok' => true]);
    }

    public static function mapStatus(string $status): string
    {
        return match ($status) {
            'success' => 'success',
            'failure', 'error' => 'failure',
            'reversed' => 'reversed',
            'hold_wait' => 'hold',
            default => 'processing',
        };
    }
}
