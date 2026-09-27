<?php

declare(strict_types=1);

namespace Commerce\Core\Update;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Boots the just-switched release through the real web stack before maintenance
 * mode is released. Normal visitors cannot bypass maintenance because the probe
 * uses a private one-time token stored outside the web root.
 */
final readonly class CoreUpdateHttpProbe
{
    public function __construct(
        private HttpClientInterface $http,
        private CoreUpdateProbeTokenStore $tokens,
        private string $publicBaseUrl,
    ) {
    }

    /** @return array{status:int,version:string,database:bool,installation:bool} */
    public function run(string $expectedVersion): array
    {
        $base = rtrim(trim($this->publicBaseUrl), '/');
        if ($base === '' || filter_var($base, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $base)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0ccf2dd860f3'));
        }

        $token = $this->tokens->create();
        try {
            $response = $this->http->request('GET', $base . '/__health/core-update', [
                'headers' => [
                    'X-Commerce-Update-Probe' => $token,
                    'Accept' => 'application/json',
                    'Cache-Control' => 'no-cache',
                ],
                'timeout' => 12.0,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            if ($status !== 200) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b83e49816a6f') . $status . '.');
            }
            $payload = $response->toArray(false);
            if (!is_array($payload)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7ee49d083e0b'));
            }
            $version = (string) ($payload['version'] ?? '');
            $database = (bool) ($payload['database'] ?? false);
            $installation = (bool) ($payload['installation'] ?? false);
            if (!(bool) ($payload['ok'] ?? false) || $version !== $expectedVersion || !$database || !$installation) {
                throw new RuntimeException(sprintf(
                    \Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.eec672324f80'),
                    $version !== '' ? $version : 'unknown',
                    $database ? 'ok' : 'failed',
                    $installation ? 'ok' : 'failed',
                ));
            }

            return ['status' => $status, 'version' => $version, 'database' => true, 'installation' => true];
        } catch (Throwable $e) {
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.863e10b307e0') . $e->getMessage(), 0, $e);
        } finally {
            $this->tokens->clear();
        }
    }
}
