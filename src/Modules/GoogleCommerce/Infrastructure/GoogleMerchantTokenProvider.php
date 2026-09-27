<?php

declare(strict_types=1);

namespace Commerce\Modules\GoogleCommerce\Infrastructure;

use Google\Auth\Credentials\ServiceAccountCredentials;
use RuntimeException;

final readonly class GoogleMerchantTokenProvider
{
    public function __construct(
        private string $serviceAccountJson,
        private string $staticAccessToken = '',
    ) {}

    public function token(): string
    {
        if (trim($this->staticAccessToken) !== '') {
            return trim($this->staticAccessToken);
        }
        $raw = trim($this->serviceAccountJson);
        if ($raw === '') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.487225628ce0'));
        }
        if (is_file($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($json)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6de1fd79d8b9'));
        }
        $credentials = new ServiceAccountCredentials('https://www.googleapis.com/auth/content', $json);
        $auth = $credentials->fetchAuthToken();
        $token = (string) ($auth['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ddb3ab16c78d'));
        }
        return $token;
    }
}
