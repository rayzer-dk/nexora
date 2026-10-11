<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Http;

use Commerce\Modules\Payment\Application\InstalmentStateService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PrivatBank calls this address with {storeId, orderId, paymentState, message, signature}. The call is not trusted by itself: it only names the
 * order, and the real state is asked from the bank, so a forged call changes nothing.
 */
final readonly class PrivatPartsWebhookController
{
    public function __construct(private InstalmentStateService $state)
    {
    }

    #[Route('/webhooks/payments/privat_parts', name: 'payment_webhook_privat_parts', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $orderId = is_array($data) ? trim((string) ($data['orderId'] ?? '')) : '';
        if ($orderId === '' || strlen($orderId) > 64) {
            return new JsonResponse(['ok' => false], 400);
        }
        try {
            $this->state->sync('privat_parts', $orderId);
        } catch (\RuntimeException) {
            return new JsonResponse(['ok' => false, 'error' => 'payment_state_rejected'], 409);
        } catch (\Throwable) {
            return new JsonResponse(['ok' => false], 500);
        }

        return new JsonResponse(['ok' => true]);
    }
}
