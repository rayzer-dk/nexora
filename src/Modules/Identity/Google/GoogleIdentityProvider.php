<?php

declare(strict_types=1);

namespace Commerce\Modules\Identity\Google;

use Commerce\Modules\Identity\Contract\ExternalIdentity;
use Commerce\Modules\Identity\Contract\IdentityProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google sign-in (OAuth 2.0 authorization-code flow). The ID token is received
 * directly from Google's token endpoint over TLS, so its claims are validated
 * (issuer, audience, expiry, nonce) instead of re-verifying the JWT signature.
 */
final readonly class GoogleIdentityProvider implements IdentityProviderInterface
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function __construct(
        private HttpClientInterface $httpClient,
        private bool $enabled,
        private string $clientId,
        private string $clientSecret,
    ) {}

    public function code(): string { return 'google'; }

    public function enabled(): bool { return $this->enabled && $this->clientId !== '' && $this->clientSecret !== ''; }

    public function authorizationUrl(string $redirectUri, string $state, string $nonce): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Exchanges an authorization code for the verified identity. */
    public function exchange(string $code, string $redirectUri, string $nonce): ExternalIdentity
    {
        if (!$this->enabled() || $code === '') {
            throw new \DomainException('google_login_unavailable');
        }
        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'body' => [
                'code' => $code,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ],
            'timeout' => 8.0,
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new \DomainException('google_token_rejected');
        }
        $data = $response->toArray(false);
        $token = is_array($data) ? (string)($data['id_token'] ?? '') : '';
        return $this->verifyClaims($token, $nonce);
    }

    public function verify(string $credential): ExternalIdentity
    {
        return $this->verifyClaims($credential, null);
    }

    private function verifyClaims(string $jwt, ?string $nonce): ExternalIdentity
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \DomainException('google_token_invalid');
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true);
        $claims = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($claims)) {
            throw new \DomainException('google_token_invalid');
        }
        $issuer = (string)($claims['iss'] ?? '');
        if (!in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)
            || !hash_equals($this->clientId, (string)($claims['aud'] ?? ''))
            || (int)($claims['exp'] ?? 0) < time()
            || trim((string)($claims['sub'] ?? '')) === ''
        ) {
            throw new \DomainException('google_token_invalid');
        }
        if ($nonce !== null && !hash_equals($nonce, (string)($claims['nonce'] ?? ''))) {
            throw new \DomainException('google_token_invalid');
        }
        $email = isset($claims['email']) ? mb_strtolower(trim((string)$claims['email'])) : null;
        $verified = ($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? '') === 'true';
        $name = isset($claims['name']) ? mb_substr(trim((string)$claims['name']), 0, 190) : null;
        return new ExternalIdentity('google', (string)$claims['sub'], $email, $name !== '' ? $name : null, $verified);
    }
}
