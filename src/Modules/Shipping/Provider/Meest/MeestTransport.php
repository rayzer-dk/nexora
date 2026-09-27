<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Provider\Meest;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MeestTransport
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiBase,
        private string $token,
        private string $citiesPath,
        private string $pointsPath,
    ) {
    }

    /** @return array<mixed> */
    public function cities(string $query, int $limit): array
    {
        return $this->get($this->citiesPath, ['query' => $query, 'limit' => $limit]);
    }

    /** @return array<mixed> */
    public function points(string $cityId, int $limit): array
    {
        return $this->get($this->pointsPath, ['city_id' => $cityId, 'limit' => $limit]);
    }

    /** @param array<string,scalar> $query
     *  @return array<mixed>
     */
    private function get(string $path, array $query): array
    {
        if ($this->apiBase === '' || $this->token === '' || $path === '') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.518e67828b58'));
        }
        $response = $this->httpClient->request('GET', rtrim($this->apiBase, '/') . '/' . ltrim($path, '/'), [
            'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $this->token],
            'query' => $query,
            'timeout' => 3.0,
        ]);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.8b0606832d79') . $response->getStatusCode() . '.');
        }
        $data = $response->toArray(false);
        if (!is_array($data)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.e2ea95b5c048'));
        }
        return $data;
    }
}
