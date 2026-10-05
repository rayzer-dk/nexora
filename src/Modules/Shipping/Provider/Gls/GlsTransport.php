<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Provider\Gls;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GLS parcel shops from the endpoint your GLS country office gives you (base URL, path and token are set in .env,
 * as for Meest): the response is read tolerantly, see GlsDeliveryProvider.
 */
final readonly class GlsTransport
{
    public function __construct(private HttpClientInterface $httpClient, private string $apiBase, private string $token, private string $pointsPath)
    {
    }

    public function configured(): bool { return $this->apiBase !== '' && $this->pointsPath !== ''; }

    /** @return array<mixed> */
    public function points(string $countryCode, string $locality, int $limit): array
    {
        if (!$this->configured()) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('shipping.error.gls_not_configured'));
        }
        $headers = ['Accept' => 'application/json'];
        if ($this->token !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }
        $response = $this->httpClient->request('GET', rtrim($this->apiBase, '/') . '/' . ltrim($this->pointsPath, '/'), [
            'headers' => $headers,
            'query' => ['country' => $countryCode, 'countryCode' => $countryCode, 'city' => $locality, 'limit' => $limit],
            'timeout' => 4.0,
        ]);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('shipping.error.http', ['carrier' => 'GLS', 'code' => (string) $response->getStatusCode()]));
        }

        return $response->toArray(false);
    }
}
