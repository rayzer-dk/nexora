<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Bot;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class TurnstileVerifier
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly bool $enabled,
        private readonly string $secretKey,
    ) {
    }

    public function enabled(): bool
    {
        return $this->enabled && trim($this->secretKey) !== '';
    }

    public function verify(string $responseToken, ?string $remoteIp = null): bool
    {
        if (!$this->enabled()) {
            return true;
        }
        if (trim($responseToken) === '') {
            return false;
        }

        $body = ['secret' => $this->secretKey, 'response' => $responseToken];
        if ($remoteIp !== null && $remoteIp !== '') {
            $body['remoteip'] = $remoteIp;
        }

        try {
            $response = $this->http->request('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'body' => $body,
                'timeout' => 4.0,
            ]);

            $status = $response->getStatusCode();
            // Turnstile is a step-up anti-bot layer, not a hard dependency of checkout.
            // A provider outage must not stop legitimate orders; local rate/honeypot checks remain active.
            if ($status >= 500) {
                return true;
            }
            if ($status >= 300) {
                return false;
            }

            $payload = $response->toArray(false);
            return ($payload['success'] ?? false) === true;
        } catch (TransportExceptionInterface) {
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
