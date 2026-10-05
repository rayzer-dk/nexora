<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Provider\Stripe;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Stripe Checkout (hosted page): the secret key creates sessions, the webhook secret verifies "Stripe-Signature". */
final readonly class StripeClient
{
    private const API = 'https://api.stripe.com/v1';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $secretKey,
        private string $webhookSecret,
    ) {}

    public function configured(): bool { return $this->secretKey !== ''; }

    /** @param array<string,mixed> $form @return array<string,mixed> */
    public function post(string $path, array $form, ?string $idempotencyKey = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->secretKey, 'Stripe-Version' => '2024-06-20'];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        $response = $this->httpClient->request('POST', self::API . $path, ['headers' => $headers, 'body' => $form, 'timeout' => 10.0]);
        $data = $response->toArray(false);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new \RuntimeException('Stripe: ' . (string) ($data['error']['message'] ?? ('HTTP ' . $response->getStatusCode())));
        }

        return $data;
    }

    /** @return array<string,mixed> */
    public function get(string $path): array
    {
        $response = $this->httpClient->request('GET', self::API . $path, ['headers' => ['Authorization' => 'Bearer ' . $this->secretKey, 'Stripe-Version' => '2024-06-20'], 'timeout' => 10.0]);
        $data = $response->toArray(false);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Stripe: ' . (string) ($data['error']['message'] ?? ('HTTP ' . $response->getStatusCode())));
        }

        return $data;
    }

    /** Header format: "t=<unix>,v1=<hmac_sha256(t.payload)>"; deliveries older than five minutes are refused. */
    public function verify(string $payload, string $header, ?int $now = null): bool
    {
        if ($this->webhookSecret === '' || $header === '') {
            return false;
        }
        $timestamp = 0;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $timestamp = (int) $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }
        if ($timestamp === 0 || abs(($now ?? time()) - $timestamp) > 300) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $this->webhookSecret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
