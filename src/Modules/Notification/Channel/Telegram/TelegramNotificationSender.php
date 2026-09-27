<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Channel\Telegram;

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
    ) {
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Telegram;
    }

    public function send(NotificationMessage $message, string $recipient): void
    {
        if (!$this->enabled) {
            return;
        }

        $chatId = trim($recipient) !== '' ? trim($recipient) : trim($this->defaultChatId);
        if ($chatId === '' || trim($this->botToken) === '') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b0603b9d3a35'));
        }

        $safeSubject = htmlspecialchars($message->subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeText = htmlspecialchars($message->text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $response = $this->http->request(
            'POST',
            'https://api.telegram.org/bot' . $this->botToken . '/sendMessage',
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
