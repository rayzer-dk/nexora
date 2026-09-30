<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Provider\LiqPay;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** LiqPay API v3: signature = base64(sha1(private . data . private)), data = base64(json). */
final readonly class LiqPayClient
{
    public const CHECKOUT_URL = 'https://www.liqpay.ua/api/3/checkout';
    public const API_URL = 'https://www.liqpay.ua/api/request';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $publicKey,
        private string $privateKey,
        private bool $sandbox,
    ) {}

    public function configured(): bool { return $this->publicKey !== '' && $this->privateKey !== ''; }
    public function sandbox(): bool { return $this->sandbox; }
    public function publicKey(): string { return $this->publicKey; }

    /** @param array<string,mixed> $payload */
    public function encode(array $payload): string
    {
        return base64_encode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function sign(string $data): string
    {
        return base64_encode(sha1($this->privateKey . $data . $this->privateKey, true));
    }

    /** Accepts sha1 (documented) and sha3-256 (announced by LiqPay); both are keyed by the private key. */
    public function verify(string $data, string $signature): bool
    {
        if ($this->privateKey === '' || $data === '' || $signature === '') {
            return false;
        }
        $sha1 = $this->sign($data);
        $sha3 = base64_encode(hash('sha3-256', $this->privateKey . $data . $this->privateKey, true));
        return hash_equals($sha1, $signature) || hash_equals($sha3, $signature);
    }

    /** @return array<string,mixed> */
    public function decode(string $data): array
    {
        $json = base64_decode($data, true);
        if ($json === false) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function checkoutUrl(array $payload): string
    {
        $data = $this->encode($payload);
        return self::CHECKOUT_URL . '?' . http_build_query(['data' => $data, 'signature' => $this->sign($data)], '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function request(array $payload): array
    {
        if (!$this->configured()) {
            throw new \RuntimeException('liqpay_not_configured');
        }
        $data = $this->encode($payload + ['version' => 3, 'public_key' => $this->publicKey]);
        $response = $this->httpClient->request('POST', self::API_URL, [
            'body' => ['data' => $data, 'signature' => $this->sign($data)],
            'headers' => ['Accept' => 'application/json'],
            'timeout' => 10.0,
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('liqpay_http_error');
        }
        $body = $response->toArray(false);
        if (!is_array($body)) {
            throw new \RuntimeException('liqpay_bad_response');
        }
        return $body;
    }
}
