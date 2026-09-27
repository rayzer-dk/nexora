<?php

declare(strict_types=1);

namespace Commerce\Modules\Search\Infrastructure;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MeilisearchClient
{
    public function __construct(
        private HttpClientInterface $http,
        private bool $enabled,
        private string $endpoint,
        private string $apiKey,
        private string $indexUid,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled && trim($this->endpoint) !== '' && trim($this->indexUid) !== '';
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function search(array $payload): array
    {
        return $this->request('POST', '/indexes/' . rawurlencode($this->indexUid) . '/search', $payload);
    }

    /** @param list<array<string,mixed>> $documents */
    public function upsertDocuments(array $documents): void
    {
        if ($documents === []) {
            return;
        }
        $this->request('POST', '/indexes/' . rawurlencode($this->indexUid) . '/documents?primaryKey=id', $documents);
    }

    public function deleteProductDocuments(int $productId): void
    {
        $this->request('POST', '/indexes/' . rawurlencode($this->indexUid) . '/documents/delete', [
            'filter' => 'product_id = ' . $productId,
        ]);
    }

    public function clearDocuments(): void
    {
        $this->request('DELETE', '/indexes/' . rawurlencode($this->indexUid) . '/documents');
    }

    public function ensureIndexSettings(): void
    {
        try {
            $this->request('POST', '/indexes', ['uid' => $this->indexUid, 'primaryKey' => 'id']);
        } catch (\Throwable) {
            // Existing index returns a conflict; settings below remain authoritative.
        }
        $this->request('PATCH', '/indexes/' . rawurlencode($this->indexUid) . '/settings', [
            'searchableAttributes' => ['name', 'sku', 'gtin', 'mpn', 'brand_name', 'short_description', 'attribute_text'],
            'filterableAttributes' => ['product_id', 'store_id', 'market_id', 'locale', 'currency', 'status', 'brand_id', 'category_ids', 'in_stock', 'price_minor', 'attribute_tokens'],
            'sortableAttributes' => ['price_minor', 'updated_ts', 'name_sort', 'product_id'],
            'displayedAttributes' => ['product_id'],
            'typoTolerance' => ['enabled' => true],
            'pagination' => ['maxTotalHits' => 5000],
        ]);
    }

    /** @return array<string,mixed> */
    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    /** @param array<string,mixed>|list<array<string,mixed>>|null $json @return array<string,mixed> */
    private function request(string $method, string $path, array|null $json = null): array
    {
        if (!$this->isEnabled()) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a0f68fea1f4f'));
        }

        $base = rtrim($this->endpoint, '/');
        $parts = parse_url($base);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true) || trim((string)($parts['host'] ?? '')) === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.91950534fc8f'));
        }
        $headers = ['Accept' => 'application/json'];
        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        $options = ['headers' => $headers, 'timeout' => 1.2, 'max_duration' => 1.8];
        if ($json !== null) {
            $options['json'] = $json;
        }
        $response = $this->http->request($method, $base . $path, $options);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.ff1bf81a33a2'), $status));
        }
        $body = $response->toArray(false);
        return is_array($body) ? $body : [];
    }
}
