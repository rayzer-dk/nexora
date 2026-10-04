<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Channel\Sms;

use RuntimeException;

/**
 * SMS-fly (sms-fly.ua) JSON API v2: one POST with {"auth":{"key"},"action":"SENDMESSAGE","data":{...}} to the address
 * shown in the provider's cabinet (API settings). The API key goes into the body, not into a header. The request
 * follows the public API description; it has not been run against a live account yet.
 */
final class SmsFlyApi
{
    public const ENDPOINT = 'https://sms-fly.ua/api/v2/api.php';

    /** @return array<string,mixed> */
    public static function request(string $apiKey, string $alphaName, string $recipient, string $text): array
    {
        return [
            'auth' => ['key' => $apiKey],
            'action' => 'SENDMESSAGE',
            'data' => [
                'recipient' => ltrim($recipient, '+'),
                'channels' => ['sms'],
                'sms' => ['source' => $alphaName, 'ttl' => 5, 'text' => $text],
            ],
        ];
    }

    /** @param array<string,mixed>|null $answer decoded JSON of the response @throws RuntimeException when the provider did not accept the message */
    public static function assertAccepted(?array $answer): void
    {
        if (is_array($answer) && !empty($answer['success'])) {
            return;
        }
        $error = is_array($answer) ? ($answer['error'] ?? null) : null;
        $detail = is_array($error) ? trim((string) ($error['description'] ?? '') . ' ' . (string) ($error['code'] ?? '')) : (is_string($error) ? $error : '');
        throw new RuntimeException('SMS-fly: ' . ($detail !== '' ? mb_substr($detail, 0, 200, 'UTF-8') : \Commerce\Core\I18n\CanonicalUiText::get('sms.runtime.unexpected_answer')));
    }
}
