<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Provider\WayForPay;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** WayForPay API: HMAC_MD5 over ';'-joined fields keyed by the merchant secret. */
final readonly class WayForPayClient
{
    public const API_URL = 'https://api.wayforpay.com/api';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $merchantAccount,
        private string $secretKey,
        private string $domain,
    ) {}

    public function configured(): bool { return $this->merchantAccount !== '' && $this->secretKey !== '' && $this->domain !== ''; }
    public function merchantAccount(): string { return $this->merchantAccount; }
    public function domain(): string { return $this->domain; }

    /** @param list<scalar> $fields */
    public function sign(array $fields): string
    {
        return hash_hmac('md5', implode(';', array_map(static fn(mixed $v): string => (string)$v, $fields)), $this->secretKey);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function request(array $payload): array
    {
        if (!$this->configured()) {
            throw new \RuntimeException('wayforpay_not_configured');
        }
        $response = $this->httpClient->request('POST', self::API_URL, ['json' => $payload, 'timeout' => 10.0, 'headers' => ['Accept' => 'application/json']]);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('wayforpay_http_error');
        }
        $body = $response->toArray(false);
        if (!is_array($body)) {
            throw new \RuntimeException('wayforpay_bad_response');
        }
        return $body;
    }

    /** Callback signature: merchantAccount;orderReference;amount;currency;authCode;cardPan;transactionStatus;reasonCode */
    public function verifyCallback(array $d): bool
    {
        $given = (string)($d['merchantSignature'] ?? '');
        if ($given === '' || $this->secretKey === '' || ($d['merchantAccount'] ?? '') !== $this->merchantAccount) {
            return false;
        }
        $expected = $this->sign([
            $d['merchantAccount'] ?? '', $d['orderReference'] ?? '', $d['amount'] ?? '', $d['currency'] ?? '',
            $d['authCode'] ?? '', $d['cardPan'] ?? '', $d['transactionStatus'] ?? '', $d['reasonCode'] ?? '',
        ]);
        return hash_equals($expected, $given);
    }
}
