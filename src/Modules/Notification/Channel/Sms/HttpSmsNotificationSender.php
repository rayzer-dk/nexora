<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Channel\Sms;

use Commerce\Core\Security\OutboundUrlPolicy;
use Commerce\Modules\Notification\Contract\NotificationSenderInterface;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HttpSmsNotificationSender implements NotificationSenderInterface
{
    public function __construct(
        private HttpClientInterface $http,
        private OutboundUrlPolicy $urlPolicy,
        private bool $enabled,
        private string $endpoint,
        private string $bearerToken,
        private string $sender,
        private ?\Commerce\Modules\Notification\Application\SmsSettings $settings = null,
    ) {}

    public function channel(): NotificationChannel { return NotificationChannel::Sms; }

    public function send(NotificationMessage $message, string $recipient): void
    {
        // The gateway saved in the admin wins; installations that never opened the SMS page keep using the SMS_* environment variables.
        $saved = $this->settings?->active();
        $enabled = $saved !== null ? true : $this->enabled;
        $endpoint = $saved !== null ? $saved['endpoint'] : $this->endpoint;
        $bearerToken = $saved !== null ? $saved['token'] : $this->bearerToken;
        $from = $saved !== null ? $saved['sender'] : $this->sender;
        $smsFly = $saved !== null && $saved['driver'] === 'smsfly';
        if ($smsFly && trim($endpoint) === '') $endpoint = SmsFlyApi::ENDPOINT;
        if (!$enabled) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ba7027dcc1d3'));
        $recipient = trim($recipient);
        if ($recipient === '' || !preg_match('/^\+?[1-9]\d{7,14}$/', $recipient)) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f13ba9bf3d0a'));
        $endpoint = trim($endpoint);
        if ($endpoint === '') throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2c189062c1cd'));
        $this->urlPolicy->assertPublicHttps($endpoint);
        if ($smsFly) {
            $answer = $this->http->request('POST', $endpoint, [
                'headers' => ['Accept' => 'application/json'],
                'json' => SmsFlyApi::request(trim($bearerToken), trim($from), $recipient, $message->text),
                'max_redirects' => 0,
                'timeout' => 8.0,
            ]);
            if ($answer->getStatusCode() >= 300) throw new RuntimeException('SMS-fly: HTTP ' . $answer->getStatusCode());
            $decoded = json_decode($answer->getContent(false), true);
            SmsFlyApi::assertAccepted(is_array($decoded) ? $decoded : null);

            return;
        }
        $headers = ['Accept' => 'application/json'];
        if (trim($bearerToken) !== '') $headers['Authorization'] = 'Bearer ' . trim($bearerToken);
        $response = $this->http->request('POST', $endpoint, [
            'headers' => $headers,
            'json' => ['to' => $recipient, 'from' => trim($from), 'message' => $message->text] + (!empty($message->context['flash']) ? ['flash' => true] : []),
            'max_redirects' => 0,
            'timeout' => 5.0,
        ]);
        if ($response->getStatusCode() >= 300) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3e3798c52b0e'));
    }
}
