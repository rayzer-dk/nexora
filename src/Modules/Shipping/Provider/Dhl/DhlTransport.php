<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Provider\Dhl;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** DHL Location Finder API (service points, lockers and post offices); needs a DHL developer API key. */
final readonly class DhlTransport
{
    public function __construct(private HttpClientInterface $httpClient, private string $apiBase, private string $apiKey)
    {
    }

    public function configured(): bool { return $this->apiKey !== ''; }

    /** @return array<mixed> */
    public function findByAddress(string $countryCode, string $locality, int $limit): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('shipping.error.dhl_not_configured'));
        }
        $response = $this->httpClient->request('GET', rtrim($this->apiBase, '/') . '/find-by-address', [
            'headers' => ['Accept' => 'application/json', 'DHL-API-Key' => $this->apiKey],
            'query' => ['countryCode' => $countryCode, 'addressLocality' => $locality, 'limit' => $limit],
            'timeout' => 4.0,
        ]);
        if ($response->getStatusCode() === 404) {
            return [];
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('shipping.error.http', ['carrier' => 'DHL', 'code' => (string) $response->getStatusCode()]));
        }

        return $response->toArray(false);
    }
}
