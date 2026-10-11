<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Messenger;

use Commerce\Modules\Notification\Contract\NotificationSenderInterface;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** A message from the shop's Viber account to a customer who linked Viber to an order. */
final class ViberCustomerSender implements NotificationSenderInterface
{
    public function __construct(private readonly HttpClientInterface $http, private readonly MessengerSettings $settings)
    {
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Viber;
    }

    public function send(NotificationMessage $message, string $recipient): void
    {
        if (!$this->settings->viberReady()) {
            return;
        }
        $this->call('send_message', ['receiver' => $recipient, 'min_api_version' => 1, 'sender' => ['name' => $this->settings->viberName()], 'type' => 'text', 'text' => mb_substr(trim($message->subject . "\n" . $message->text), 0, 7000)]);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function call(string $method, array $payload): array
    {
        $response = $this->http->request('POST', $this->settings->viberApiBase() . '/pa/' . $method, ['json' => $payload, 'headers' => ['X-Viber-Auth-Token' => $this->settings->viberToken()], 'timeout' => 8.0]);
        $status = $response->getStatusCode();
        $data = json_decode($response->getContent(false), true);
        if ($status < 200 || $status >= 300 || (is_array($data) && (int) ($data['status'] ?? 0) !== 0)) {
            throw new \RuntimeException('Viber answered ' . $status . ': ' . mb_substr(is_array($data) ? (string) ($data['status_message'] ?? '') : '', 0, 200));
        }

        return is_array($data) ? $data : [];
    }
}
