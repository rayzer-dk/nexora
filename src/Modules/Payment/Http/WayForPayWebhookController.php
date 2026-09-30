<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Http;

use Commerce\Modules\Payment\Application\PaymentLifecycleService;
use Commerce\Modules\Payment\Provider\WayForPay\WayForPayClient;
use Commerce\Modules\Security\Webhook\WebhookReplayGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class WayForPayWebhookController
{
    public function __construct(
        private WayForPayClient $client,
        private PaymentLifecycleService $lifecycle,
        private WebhookReplayGuard $replays,
    ) {}

    #[Route('/webhooks/payments/wayforpay', name: 'payment_webhook_wayforpay', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $d = json_decode($raw, true);
        if (!is_array($d) && $request->request->count() > 0) {
            $d = $request->request->all();
        }
        if (!is_array($d) || !$this->client->verifyCallback($d)) {
            return new JsonResponse(['ok' => false], 401);
        }
        $order = trim((string)($d['orderReference'] ?? ''));
        $status = trim((string)($d['transactionStatus'] ?? ''));
        if ($order === '' || $status === '') {
            return new JsonResponse(['ok' => false], 400);
        }
        $fingerprint = hash('sha256', $raw . "\0" . (string)$d['merchantSignature']);
        if ($this->replays->claim('wayforpay', $fingerprint)) {
            try {
                $this->lifecycle->applyProviderStatus(
                    'wayforpay', $order, self::mapStatus($status),
                    isset($d['processingDate']) && is_numeric($d['processingDate']) ? gmdate('c', (int)$d['processingDate']) : null,
                    (int)round(((float)($d['amount'] ?? 0)) * 100),
                    strtoupper((string)($d['currency'] ?? '')),
                    isset($d['reason']) ? mb_substr((string)$d['reason'], 0, 250) : null,
                    $d,
                );
            } catch (\RuntimeException) {
                $this->replays->release('wayforpay', $fingerprint);
                return new JsonResponse(['ok' => false, 'error' => 'payment_state_rejected'], 409);
            } catch (\Throwable $e) {
                $this->replays->release('wayforpay', $fingerprint);
                throw $e;
            }
        }
        $time = time();
        return new JsonResponse([
            'orderReference' => $order,
            'status' => 'accept',
            'time' => $time,
            'signature' => $this->client->sign([$order, 'accept', $time]),
        ]);
    }

    public static function mapStatus(string $status): string
    {
        return match (strtolower($status)) {
            'approved' => 'success',
            'declined' => 'failure',
            'expired' => 'expired',
            'refunded', 'voided' => 'reversed',
            default => 'processing',
        };
    }
}
