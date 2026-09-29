<?php

declare(strict_types=1);

namespace Commerce\Core\Runtime;

use Commerce\Core\Platform\PlatformVersion;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Optional push of runtime incidents to an external monitor (Sentry-style relay, Slack/Teams bridge, n8n, etc.).
 * Disabled unless ERROR_WEBHOOK_URL is an https URL. The payload has no stack trace, request body or personal data.
 */
final readonly class IncidentWebhookNotifier
{
    public function __construct(private HttpClientInterface $http, private string $url = '')
    {
    }

    public function enabled(): bool
    {
        return str_starts_with($this->url, 'https://');
    }

    public function notify(string $requestId, string $area, ?string $route, string $errorClass, string $summary, string $fallback): void
    {
        if (!$this->enabled()) {
            return;
        }
        try {
            $this->http->request('POST', $this->url, [
                'json' => [
                    'source' => 'nexora-commerce',
                    'version' => PlatformVersion::VERSION,
                    'request_id' => $requestId,
                    'area' => $area,
                    'route' => $route,
                    'error_class' => $errorClass,
                    'summary' => mb_substr($summary, 0, 500, 'UTF-8'),
                    'fallback' => $fallback,
                    'text' => sprintf('[nexora %s] %s: %s (%s)', $area, $errorClass, mb_substr($summary, 0, 200, 'UTF-8'), $requestId),
                ],
                'timeout' => 2,
                'max_duration' => 3,
                'max_redirects' => 0,
            ])->getStatusCode();
        } catch (Throwable) {
            // Monitoring must never become a second failure path.
        }
    }
}
