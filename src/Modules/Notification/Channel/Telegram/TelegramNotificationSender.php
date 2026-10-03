<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Channel\Telegram;

use Commerce\Modules\Notification\Application\NotificationChannelSettings;
use Commerce\Modules\Notification\Contract\NotificationSenderInterface;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TelegramNotificationSender implements NotificationSenderInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly bool $enabled,
        private readonly string $botToken,
        private readonly string $defaultChatId,
        private readonly ?NotificationChannelSettings $channels = null,
        private readonly string $apiBase = 'https://api.telegram.org',
    ) {
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Telegram;
    }

    public function send(NotificationMessage $message, string $recipient): void
    {
        $saved = $this->channels?->active();
        $fromAdmin = $saved !== null && $saved['tg_enabled'] && $saved['has_tg_token'];
        if (!$this->enabled && !$fromAdmin) {
            return;
        }
        $token = $fromAdmin ? $saved['tg_token'] : $this->botToken;
        $defaultChat = $fromAdmin && $saved['tg_chat_id'] !== '' ? $saved['tg_chat_id'] : $this->defaultChatId;

        $chatId = trim($recipient) !== '' ? trim($recipient) : trim($defaultChat);
        if ($chatId === '' || trim($token) === '') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b0603b9d3a35'));
        }

        $safeSubject = htmlspecialchars($message->subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeText = htmlspecialchars($message->text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $response = $this->http->request(
            'POST',
            rtrim($this->apiBase, '/') . '/bot' . $token . '/sendMessage',
            [
                'json' => [
                    'chat_id' => $chatId,
                    'text' => '<b>' . $safeSubject . "</b>\n" . $safeText,
                    'parse_mode' => 'HTML',
                ],
                'timeout' => 5.0,
            ],
        );

        if ($response->getStatusCode() >= 300) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.83af4e8fbee7'));
        }
    }
}
