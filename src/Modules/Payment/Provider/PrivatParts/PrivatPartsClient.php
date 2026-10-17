<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Provider\PrivatParts;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * PrivatBank "Оплата частинами" (PayParts v2). Every request is signed: base64(sha1(password + fields + password)) with the raw sha1 digest.
 * Amounts in the JSON are decimal strings with two digits; in the signature they are whole kopecks.
 */
final readonly class PrivatPartsClient
{
    public function __construct(private HttpClientInterface $httpClient, private string $apiBase, private string $storeId, private string $password)
    {
    }

    public static function kopecks(int $minor): string
    {
        return (string) $minor;
    }

    public static function decimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    /** @param list<string> $parts the fields between the two passwords, in the order of the bank's description */
    public static function sign(string $password, array $parts): string
    {
        return base64_encode(sha1($password . implode('', $parts) . $password, true));
    }

    /**
     * @param list<array{name:string,count:int,price_minor:int}> $goods
     * @return array<string,mixed> the token and the address of the bank page
     */
    public function create(string $orderId, int $amountMinor, int $partsCount, string $merchantType, array $goods, string $responseUrl, string $redirectUrl): array
    {
        $products = [];
        $productString = '';
        foreach ($goods as $line) {
            $products[] = ['name' => $line['name'], 'count' => $line['count'], 'price' => self::decimal($line['price_minor'])];
            $productString .= $line['name'] . $line['count'] . self::kopecks($line['price_minor']);
        }
        $signature = self::sign($this->password, [$this->storeId, $orderId, self::kopecks($amountMinor), (string) $partsCount, $merchantType, $responseUrl, $redirectUrl, $productString]);
        $data = $this->post('/ipp/v2/payment/create', [
            'storeId' => $this->storeId, 'orderId' => $orderId, 'amount' => self::decimal($amountMinor), 'partsCount' => $partsCount, 'merchantType' => $merchantType,
            'products' => $products, 'responseUrl' => $responseUrl, 'redirectUrl' => $redirectUrl, 'signature' => $signature,
        ]);
        $token = trim((string) ($data['token'] ?? ''));
        if ($token === '') {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.error.payment.privat_parts.no_token_prefix') . mb_substr((string) ($data['message'] ?? ''), 0, 200));
        }

        return ['token' => $token, 'url' => rtrim($this->apiBase, '/') . '/ipp/v2/payment?token=' . rawurlencode($token)];
    }

    /** @return array<string,mixed> */
    public function state(string $orderId): array
    {
        return $this->post('/ipp/v2/payment/state', [
            'storeId' => $this->storeId, 'orderId' => $orderId, 'showRefund' => 'true',
            'signature' => self::sign($this->password, [$this->storeId, $orderId]),
        ]);
    }

    /** @return array<string,mixed> */
    public function decline(string $orderId, int $amountMinor): array
    {
        return $this->post('/ipp/v2/payment/decline', [
            'storeId' => $this->storeId, 'orderId' => $orderId, 'amount' => self::decimal($amountMinor),
            'signature' => self::sign($this->password, [$this->storeId, $orderId, self::kopecks($amountMinor)]),
        ]);
    }

    /** @return array<string,mixed> */
    public function cancel(string $orderId): array
    {
        return $this->post('/ipp/v2/payment/cancel', ['storeId' => $this->storeId, 'orderId' => $orderId, 'signature' => self::sign($this->password, [$this->storeId, $orderId])]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function post(string $path, array $payload): array
    {
        if ($this->storeId === '' || $this->password === '') {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.error.payment.privat_parts.not_configured'));
        }
        $response = $this->httpClient->request('POST', rtrim($this->apiBase, '/') . $path, [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Accept-Encoding' => 'UTF-8'],
            'body' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timeout' => 10.0,
        ]);
        $status = $response->getStatusCode();
        $data = json_decode($response->getContent(false), true);
        if ($status > 201 || !is_array($data)) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.error.payment.privat_parts.http_prefix') . $status);
        }

        return $data;
    }
}
