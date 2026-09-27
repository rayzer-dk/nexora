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
    ) {}

    public function channel(): NotificationChannel { return NotificationChannel::Sms; }

    public function send(NotificationMessage $message, string $recipient): void
    {
        if (!$this->enabled) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ba7027dcc1d3'));
        $recipient = trim($recipient);
        if ($recipient === '' || !preg_match('/^\+?[1-9]\d{7,14}$/', $recipient)) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f13ba9bf3d0a'));
        $endpoint = trim($this->endpoint);
        if ($endpoint === '') throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2c189062c1cd'));
        $this->urlPolicy->assertPublicHttps($endpoint);
        $headers = ['Accept' => 'application/json'];
        if (trim($this->bearerToken) !== '') $headers['Authorization'] = 'Bearer ' . trim($this->bearerToken);
        $response = $this->http->request('POST', $endpoint, [
            'headers' => $headers,
            'json' => ['to' => $recipient, 'from' => trim($this->sender), 'message' => $message->text],
            'max_redirects' => 0,
            'timeout' => 5.0,
        ]);
        if ($response->getStatusCode() >= 300) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3e3798c52b0e'));
    }
}
