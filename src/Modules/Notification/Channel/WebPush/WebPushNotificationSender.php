<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Channel\WebPush;

use Commerce\Modules\Notification\Contract\NotificationSenderInterface;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Commerce\Modules\Push\Application\PushSettings;
use Commerce\Modules\Push\Application\PushSubscriptionService;
use Commerce\Modules\Push\Application\WebPushCrypto;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Recipient format: "<audience>:<storeId>", for example "admin:1" or "storefront:1". */
final class WebPushNotificationSender implements NotificationSenderInterface
{
    private const BATCH = 50;
    private const MAX_RECIPIENTS = 5000;

    public function __construct(
        private readonly PushSettings $settings,
        private readonly PushSubscriptionService $subscriptions,
        private readonly HttpClientInterface $http,
    ) {
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::WebPush;
    }

    public function send(NotificationMessage $message, string $recipient): void
    {
        if (preg_match('/^(admin|storefront):(\d+)$/', $recipient, $m) !== 1) {
            throw new \InvalidArgumentException('webpush_invalid_recipient');
        }
        $this->deliver((int) $m[2], $m[1], $message->subject, $message->text, (string) ($message->context['url'] ?? '/'));
    }

    /** @return array{sent:int,failed:int,removed:int} */
    public function deliver(int $storeId, string $audience, string $title, string $body, string $url): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'removed' => 0];
        $material = $this->settings->signingMaterial($storeId);
        if ($material === null) {
            return $stats;
        }
        $url = (str_starts_with($url, '/') && !str_starts_with($url, '//')) || preg_match('#^https://#', $url) === 1 ? mb_substr($url, 0, 500) : '/';
        $payload = json_encode(['title' => mb_substr($title, 0, 80), 'body' => mb_substr($body, 0, 240), 'url' => $url], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rows = $this->subscriptions->forAudience($storeId, $audience, self::MAX_RECIPIENTS);
        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $pending = [];
            foreach ($chunk as $row) {
                try {
                    $pending[(int) $row['id']] = $this->http->request('POST', (string) $row['endpoint'], [
                        'headers' => [
                            'Content-Encoding' => 'aes128gcm',
                            'Content-Type' => 'application/octet-stream',
                            'TTL' => '86400',
                            'Urgency' => 'normal',
                            'Authorization' => WebPushCrypto::vapidHeader((string) $row['endpoint'], $material['subject'], $material['private_pem'], $material['public_raw']),
                        ],
                        'body' => WebPushCrypto::encrypt($payload, WebPushCrypto::b64uDecode((string) $row['p256dh']), WebPushCrypto::b64uDecode((string) $row['auth'])),
                        'timeout' => 8.0,
                        'max_redirects' => 0,
                    ]);
                } catch (\Throwable) {
                    $this->subscriptions->failure((int) $row['id'], false);
                    ++$stats['failed'];
                }
            }
            foreach ($pending as $id => $response) {
                try {
                    $status = $response->getStatusCode();
                } catch (\Throwable) {
                    $this->subscriptions->failure($id, false);
                    ++$stats['failed'];
                    continue;
                }
                if ($status >= 200 && $status < 300) {
                    $this->subscriptions->success($id);
                    ++$stats['sent'];
                } elseif ($status === 404 || $status === 410) {
                    $this->subscriptions->failure($id, true);
                    ++$stats['removed'];
                } else {
                    $this->subscriptions->failure($id, false);
                    ++$stats['failed'];
                }
            }
        }

        return $stats;
    }
}
