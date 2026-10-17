<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Provider\MonoParts;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** monobank "Покупка частинами": every request carries store-id and signature = base64(HMAC-SHA256(body, secret)). */
final readonly class MonoPartsClient
{
    public function __construct(private HttpClientInterface $httpClient, private string $apiBase, private string $storeId, private string $secret)
    {
    }

    public static function sign(string $body, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $body, $secret, true));
    }

    public function verify(string $body, string $signature): bool
    {
        return $this->secret !== '' && hash_equals(self::sign($body, $this->secret), $signature);
    }

    /**
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    public function createOrder(array $order): array
    {
        return $this->post('/api/order/create', $order);
    }

    /** @return array<string,mixed> */
    public function state(string $orderId): array
    {
        return $this->post('/api/order/state', ['order_id' => $orderId]);
    }

    /** @return array<string,mixed> */
    public function confirm(string $orderId): array
    {
        return $this->post('/api/order/confirm', ['order_id' => $orderId]);
    }

    /** @return array<string,mixed> */
    public function reject(string $orderId): array
    {
        return $this->post('/api/order/reject', ['order_id' => $orderId]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function post(string $path, array $payload): array
    {
        if ($this->storeId === '' || $this->secret === '') {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.error.payment.mono_parts.not_configured'));
        }
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response = $this->httpClient->request('POST', rtrim($this->apiBase, '/') . $path, [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'store-id' => $this->storeId, 'signature' => self::sign($body, $this->secret)],
            'body' => $body,
            'timeout' => 10.0,
        ]);
        $status = $response->getStatusCode();
        $data = json_decode($response->getContent(false), true);
        if ($status < 200 || $status >= 300) {
            $detail = is_array($data) ? (string) ($data['message'] ?? $data['error'] ?? $data['detail'] ?? '') : '';
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.error.payment.mono_parts.http_prefix') . $status . ($detail !== '' ? ': ' . mb_substr($detail, 0, 200) : ''));
        }

        return is_array($data) ? $data : [];
    }
}
