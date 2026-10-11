<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Http;

use Commerce\Core\I18n\StorefrontUiTranslator;
use Commerce\Modules\Notification\Messenger\MessengerLinkService;
use Commerce\Modules\Notification\Messenger\MessengerSettings;
use Commerce\Modules\Notification\Messenger\TelegramCustomerSender;
use Commerce\Modules\Notification\Messenger\ViberCustomerSender;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** What Telegram and Viber tell the shop: a customer opened the bot with a link token, or stopped it. */
final readonly class MessengerWebhookController
{
    public function __construct(
        private MessengerSettings $settings,
        private MessengerLinkService $links,
        private TelegramCustomerSender $telegram,
        private ViberCustomerSender $viber,
        private StorefrontUiTranslator $translator,
        private Connection $db,
    ) {
    }

    #[Route('/webhooks/messengers/telegram/{secret}', name: 'messenger_webhook_telegram', methods: ['POST'], requirements: ['secret' => '[a-f0-9]{32}'])]
    public function telegram(string $secret, Request $request): JsonResponse
    {
        if (!$this->settings->telegramReady() || !hash_equals($this->settings->webhookSecret(), $secret)) {
            return new JsonResponse(['ok' => false], 404);
        }
        $update = json_decode($request->getContent(), true);
        $message = is_array($update) && is_array($update['message'] ?? null) ? $update['message'] : null;
        $chatId = $message !== null && isset($message['chat']['id']) ? (string) $message['chat']['id'] : '';
        $text = $message !== null ? trim((string) ($message['text'] ?? '')) : '';
        if ($chatId === '' || $text === '') {
            return new JsonResponse(['ok' => true]);
        }
        try {
            if (preg_match('/^\/start(?:@\w+)?\s+([a-f0-9]{24})$/D', $text, $m) === 1) {
                $linked = $this->links->attach('telegram', $m[1], $chatId, (string) ($message['from']['first_name'] ?? ''));
                if ($linked !== null) {
                    $this->telegram->call('sendMessage', ['chat_id' => $chatId, 'text' => $this->greeting($linked['order_id'])]);
                }
            } elseif (preg_match('/^\/stop(?:@\w+)?$/D', $text) === 1) {
                $this->links->stop('telegram', $chatId);
                $this->telegram->call('sendMessage', ['chat_id' => $chatId, 'text' => $this->translator->translate('messenger_stopped', 'uk-UA')]);
            }
        } catch (\Throwable) {
            return new JsonResponse(['ok' => false], 500);
        }

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/webhooks/messengers/viber', name: 'messenger_webhook_viber', methods: ['POST'])]
    public function viber(Request $request): JsonResponse
    {
        if (!$this->settings->viberReady()) {
            return new JsonResponse(['status' => 1], 404);
        }
        $raw = $request->getContent();
        $signature = (string) $request->headers->get('X-Viber-Content-Signature', '');
        if ($signature === '' || !hash_equals(hash_hmac('sha256', $raw, $this->settings->viberToken()), $signature)) {
            return new JsonResponse(['status' => 1], 401);
        }
        $event = json_decode($raw, true);
        $type = is_array($event) ? (string) ($event['event'] ?? '') : '';
        try {
            if ($type === 'conversation_started') {
                $userId = (string) ($event['user']['id'] ?? '');
                $context = (string) ($event['context'] ?? '');
                $linked = $userId !== '' && $context !== '' ? $this->links->attach('viber', $context, $userId, (string) ($event['user']['name'] ?? '')) : null;
                if ($linked !== null) {
                    // The answer to conversation_started is shown to the user at once.
                    return new JsonResponse(['sender' => ['name' => $this->settings->viberName()], 'type' => 'text', 'text' => $this->greeting($linked['order_id'])]);
                }
            } elseif ($type === 'unsubscribed' && isset($event['user_id'])) {
                $this->links->stop('viber', (string) $event['user_id']);
            }
        } catch (\Throwable) {
            return new JsonResponse(['status' => 1], 500);
        }

        return new JsonResponse(['status' => 0]);
    }

    private function greeting(int $orderId): string
    {
        $row = $this->db->fetchAssociative('SELECT order_number,locale FROM mc_sales_order WHERE id=?', [$orderId]);
        $locale = is_array($row) && trim((string) $row['locale']) !== '' ? (string) $row['locale'] : 'uk-UA';

        return $this->translator->translate('messenger_linked', $locale, ['number' => is_array($row) ? (string) $row['order_number'] : '']);
    }
}
