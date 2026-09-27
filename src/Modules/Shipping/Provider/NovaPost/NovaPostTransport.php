<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Provider\NovaPost;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class NovaPostTransport
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiBase,
        private string $apiKey,
    ) {
    }

    /**
     * Nova Poshta Ukraine API 2.0 request.
     *
     * @param array<string,mixed> $properties
     * @return array<string,mixed>
     */
    public function call(string $modelName, string $calledMethod, array $properties = []): array
    {
        if (trim($this->apiKey) === '') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.72c4c4c8744d'));
        }

        $response = $this->httpClient->request('POST', rtrim($this->apiBase, '/') . '/', [
            'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
            'json' => [
                'apiKey' => $this->apiKey,
                'modelName' => $modelName,
                'calledMethod' => $calledMethod,
                'methodProperties' => $properties,
            ],
            'timeout' => 8.0,
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.344d752a9074') . $status . '.');
        }

        $data = $response->toArray(false);
        if (!is_array($data)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.fa5b18a00011'));
        }

        if (($data['success'] ?? false) !== true) {
            $errors = [];
            foreach ((array)($data['errors'] ?? []) as $error) {
                if (is_scalar($error) && trim((string)$error) !== '') {
                    $errors[] = trim((string)$error);
                }
            }
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.366168140a8d') . ($errors !== [] ? ': ' . implode('; ', array_slice($errors, 0, 5)) : '.'));
        }

        return $data;
    }

    /** @return list<array<string,mixed>> */
    public function rows(array $response): array
    {
        $rows = $response['data'] ?? [];
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    public function download(string $url): string
    {
        $response = $this->httpClient->request('GET', $url, ['timeout' => 12.0, 'headers' => ['Accept' => 'application/pdf']]);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) { throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.129cd0c4793d') . $status . '.'); }
        $body = $response->getContent(false);
        if ($body === '' || !str_starts_with($body, '%PDF')) { throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0c99e77cd7cf')); }
        return $body;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }
}
