<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Provider\DeliveryAuto;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class DeliveryAutoTransport
{
    public function __construct(private HttpClientInterface $httpClient, private string $apiBase)
    {
    }

    /** @param array<string,scalar|null> $query
     *  @return array<mixed>
     */
    public function get(string $method, array $query): array
    {
        $response = $this->httpClient->request('GET', rtrim($this->apiBase, '/') . '/' . ltrim($method, '/'), [
            'headers' => ['Accept' => 'application/json'],
            'query' => array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== ''),
            'timeout' => 3.0,
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.aaae0418ef22') . $response->getStatusCode() . '.');
        }
        $data = $response->toArray(false);
        if (!is_array($data)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.521e1fe843d7'));
        }
        return $data;
    }
}
