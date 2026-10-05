<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Provider\PayPal;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** PayPal Orders v2 (REST): OAuth client credentials, create → buyer approves → capture on return. */
final class PayPalClient
{
    private ?string $token = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $clientId,
        private readonly string $secret,
        private readonly bool $sandbox,
        private readonly string $webhookId,
    ) {}

    public function configured(): bool { return $this->clientId !== '' && $this->secret !== ''; }
    public function webhookConfigured(): bool { return $this->configured() && $this->webhookId !== ''; }
    public function webhookId(): string { return $this->webhookId; }

    private function base(): string { return $this->sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com'; }

    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        $response = $this->httpClient->request('POST', $this->base() . '/v1/oauth2/token', [
            'auth_basic' => [$this->clientId, $this->secret],
            'body' => ['grant_type' => 'client_credentials'],
            'timeout' => 10.0,
        ]);
        $data = $response->toArray(false);
        if ($response->getStatusCode() !== 200 || ($data['access_token'] ?? '') === '') {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('payment.paypal.auth_failed'));
        }

        return $this->token = (string) $data['access_token'];
    }

    /** @param array<string,mixed>|null $json @return array<string,mixed> */
    public function request(string $method, string $path, ?array $json = null, ?string $requestId = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token(), 'Accept' => 'application/json'];
        if ($requestId !== null) {
            $headers['PayPal-Request-Id'] = substr($requestId, 0, 100);
        }
        $options = ['headers' => $headers, 'timeout' => 12.0];
        if ($json !== null) {
            $options['json'] = $json;
        } elseif ($method === 'POST') {
            $headers['Content-Type'] = 'application/json';
            $options['headers'] = $headers;
            $options['body'] = '{}';
        }
        $response = $this->httpClient->request($method, $this->base() . $path, $options);
        $data = $response->toArray(false);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('PayPal: ' . (string) ($data['message'] ?? $data['name'] ?? ('HTTP ' . $status)));
        }

        return $data;
    }

    /** Asks PayPal to verify a webhook delivery (needs the webhook id of this endpoint). */
    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        if (!$this->webhookConfigured()) {
            return false;
        }
        try {
            $event = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
            $data = $this->request('POST', '/v1/notifications/verify-webhook-signature', [
                'auth_algo' => $headers['paypal-auth-algo'] ?? '',
                'cert_url' => $headers['paypal-cert-url'] ?? '',
                'transmission_id' => $headers['paypal-transmission-id'] ?? '',
                'transmission_sig' => $headers['paypal-transmission-sig'] ?? '',
                'transmission_time' => $headers['paypal-transmission-time'] ?? '',
                'webhook_id' => $this->webhookId,
                'webhook_event' => $event,
            ]);
        } catch (\Throwable) {
            return false;
        }

        return ($data['verification_status'] ?? '') === 'SUCCESS';
    }
}
