<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Messenger;

use Commerce\Modules\Notification\Contract\NotificationSenderInterface;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** A message from the shop's bot to a customer who linked Telegram to an order. */
final class TelegramCustomerSender implements NotificationSenderInterface
{
    public function __construct(private readonly HttpClientInterface $http, private readonly MessengerSettings $settings)
    {
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::TelegramCustomer;
    }

    public function send(NotificationMessage $message, string $recipient): void
    {
        if (!$this->settings->telegramReady()) {
            return;
        }
        $this->call('sendMessage', ['chat_id' => $recipient, 'text' => mb_substr(trim($message->subject . "\n" . $message->text), 0, 4000), 'disable_web_page_preview' => true]);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function call(string $method, array $payload): array
    {
        $response = $this->http->request('POST', $this->settings->telegramApiBase() . '/bot' . $this->settings->telegramToken() . '/' . $method, ['json' => $payload, 'timeout' => 8.0]);
        $status = $response->getStatusCode();
        $data = json_decode($response->getContent(false), true);
        if ($status < 200 || $status >= 300 || (is_array($data) && ($data['ok'] ?? true) === false)) {
            throw new \RuntimeException('Telegram answered ' . $status . ': ' . mb_substr(is_array($data) ? (string) ($data['description'] ?? '') : '', 0, 200));
        }

        return is_array($data) ? $data : [];
    }
}
