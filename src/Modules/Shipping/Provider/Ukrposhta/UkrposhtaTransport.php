<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Provider\Ukrposhta;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class UkrposhtaTransport
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiBase,
        private string $bearer,
    ) {
    }

    /** @param array<string,scalar|null> $query
     *  @return array<mixed>
     */
    public function get(string $path, array $query): array
    {
        if ($this->bearer === '') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7660791958b5'));
        }

        $response = $this->httpClient->request('GET', rtrim($this->apiBase, '/') . '/' . ltrim($path, '/'), [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->bearer,
            ],
            'query' => array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== ''),
            'timeout' => 3.0,
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.860a7a59d6c2') . $response->getStatusCode() . '.');
        }

        $data = $response->toArray(false);
        if (!is_array($data)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ce132cb1e775'));
        }

        return $data;
    }
}
