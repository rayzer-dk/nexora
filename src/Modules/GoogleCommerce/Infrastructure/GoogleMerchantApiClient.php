<?php

declare(strict_types=1);

namespace Commerce\Modules\GoogleCommerce\Infrastructure;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GoogleMerchantApiClient
{
    public function __construct(
        private HttpClientInterface $http,
        private GoogleMerchantTokenProvider $tokens,
        private string $apiBase,
        private string $accountId,
        private string $dataSourceName,
    ) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function upsertProduct(array $input): array
    {
        $this->assertConfigured();
        $url = rtrim($this->apiBase, '/') . '/products/v1/accounts/' . rawurlencode($this->accountId) . '/productInputs:insert';
        return $this->request('POST', $url, ['query' => ['dataSource' => $this->dataSourceName], 'json' => $input]);
    }

    public function deleteProduct(string $contentLanguage, string $feedLabel, string $offerId): void
    {
        $this->assertConfigured();
        $resource = rawurlencode($contentLanguage . '~' . $feedLabel . '~' . $offerId);
        $url = rtrim($this->apiBase, '/') . '/products/v1/accounts/' . rawurlencode($this->accountId) . '/productInputs/' . $resource;
        $this->request('DELETE', $url, ['query' => ['dataSource' => $this->dataSourceName]]);
    }

    /** @return array<string,mixed> */
    public function getProcessedProduct(string $name): array
    {
        $this->assertConfigured();
        $path = str_starts_with($name, 'accounts/') ? $name : 'accounts/' . $this->accountId . '/products/' . $name;
        return $this->request('GET', rtrim($this->apiBase, '/') . '/products/v1/' . $path);
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function request(string $method, string $url, array $options = []): array
    {
        $options['headers']['Authorization'] = 'Bearer ' . $this->tokens->token();
        $options['headers']['Accept'] = 'application/json';
        $options['timeout'] = 15.0;
        $response = $this->http->request($method, $url, $options);
        $status = $response->getStatusCode();
        $body = $response->getContent(false);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.a25ffe184b26'), $status, mb_substr($body, 0, 800)));
        }
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    private function assertConfigured(): void
    {
        if ($this->accountId === '' || $this->dataSourceName === '') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2708a0a708af'));
        }
    }
}
