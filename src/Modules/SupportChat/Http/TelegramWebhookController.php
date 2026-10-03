<?php

declare(strict_types=1);

namespace Commerce\Modules\SupportChat\Http;

use Commerce\Modules\SupportChat\Application\SupportChatService;
use Commerce\Modules\SupportChat\Application\SupportChatSettings;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Receives updates from Telegram. The address carries the webhook secret and Telegram repeats it in a header. */
final class TelegramWebhookController extends AbstractController
{
    public function __construct(private readonly SupportChatSettings $settings, private readonly SupportChatService $chat, private readonly LoggerInterface $logger)
    {
    }

    #[Route('/support/telegram/webhook/{secret}', name: 'storefront_support_telegram_webhook', methods: ['POST'], requirements: ['secret' => '[A-Za-z0-9_-]{16,64}'], priority: 950)]
    public function __invoke(Request $request, string $secret): Response
    {
        $store = $this->settings->findByWebhookSecret($secret);
        $header = (string) $request->headers->get('X-Telegram-Bot-Api-Secret-Token', '');
        if ($store === null || !hash_equals($secret, $header)) {
            return new JsonResponse(['ok' => false], Response::HTTP_NOT_FOUND);
        }
        $update = json_decode($request->getContent(), true);
        if (is_array($update)) {
            try {
                $this->chat->handleUpdate($store['store_id'], $update);
            } catch (\Throwable $e) {
                // Always answer 200: Telegram would otherwise retry the same update for hours.
                $this->logger->error('Support chat webhook failed.', ['exception' => $e]);
            }
        }

        return new JsonResponse(['ok' => true]);
    }
}
