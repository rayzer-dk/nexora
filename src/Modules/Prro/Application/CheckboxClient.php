<?php

declare(strict_types=1);

namespace Commerce\Modules\Prro\Application;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client of the Checkbox cash register API (api.checkbox.ua, v1): cashier sign-in, shift, sale receipt, receipt status.
 * The address is fixed per environment; PRRO_CHECKBOX_BASE_URL exists only so a mock can stand in during tests.
 */
final class CheckboxClient
{
    private const PROD = 'https://api.checkbox.ua/api/v1';
    private const TEST = 'https://dev-api.checkbox.ua/api/v1';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    /** @param array{environment:string,login:string,password:string,license:string} $cfg */
    public function token(array $cfg): string
    {
        $data = $this->request('POST', $cfg, '/cashier/signin', null, ['login' => $cfg['login'], 'password' => $cfg['password']]);
        $token = (string) ($data['access_token'] ?? '');
        if ($token === '') {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('admin.prro.error.no_token'));
        }

        return $token;
    }

    /** @param array{environment:string,login:string,password:string,license:string} $cfg */
    public function ensureShift(array $cfg, string $token): void
    {
        $shift = $this->request('GET', $cfg, '/cashier/shift', $token);
        if (strtoupper((string) ($shift['status'] ?? '')) === 'OPENED') {
            return;
        }
        $this->request('POST', $cfg, '/shifts', $token, []);
    }

    /**
     * @param array{environment:string,login:string,password:string,license:string} $cfg
     * @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    public function sell(array $cfg, string $token, array $receipt): array
    {
        return $this->request('POST', $cfg, '/receipts/sell', $token, $receipt);
    }

    /**
     * @param array{environment:string,login:string,password:string,license:string} $cfg
     * @return array<string,mixed>
     */
    public function receipt(array $cfg, string $token, string $id): array
    {
        return $this->request('GET', $cfg, '/receipts/' . rawurlencode($id), $token);
    }

    public function receiptUrl(string $environment, string $id): string
    {
        return 'https://check.checkbox.ua/' . rawurlencode($id);
    }

    /**
     * @param array{environment:string,login:string,password:string,license:string} $cfg
     * @param array<string,mixed>|null $json
     * @return array<string,mixed>
     */
    private function request(string $method, array $cfg, string $path, ?string $token, ?array $json = null): array
    {
        $headers = ['X-License-Key' => $cfg['license'], 'X-Client-Name' => 'Nexora Commerce', 'X-Client-Version' => '1', 'Accept' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $options = ['headers' => $headers, 'timeout' => 20, 'max_redirects' => 0];
        if ($json !== null) {
            $options['json'] = $json;
        }
        $response = $this->http->request($method, $this->base($cfg['environment']) . $path, $options);
        $status = $response->getStatusCode();
        $body = $response->getContent(false);
        $data = json_decode($body, true);
        $data = is_array($data) ? $data : [];
        if ($status >= 400) {
            $message = (string) ($data['message'] ?? $data['detail'] ?? '');
            if ($message === '' && is_array($data['detail'] ?? null)) {
                $message = json_encode($data['detail'], JSON_UNESCAPED_UNICODE) ?: '';
            }
            throw new CheckboxException($status, mb_substr($message !== '' ? $message : 'HTTP ' . $status, 0, 400));
        }

        return $data;
    }

    private function base(string $environment): string
    {
        $override = $_SERVER['PRRO_CHECKBOX_BASE_URL'] ?? $_ENV['PRRO_CHECKBOX_BASE_URL'] ?? getenv('PRRO_CHECKBOX_BASE_URL');
        if (is_string($override) && $override !== '') {
            return rtrim($override, '/');
        }

        return $environment === 'prod' ? self::PROD : self::TEST;
    }
}
