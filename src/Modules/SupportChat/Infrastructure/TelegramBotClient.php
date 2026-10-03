<?php

declare(strict_types=1);

namespace Commerce\Modules\SupportChat\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Thin Telegram Bot API client. Every call takes the token so one instance serves several stores. */
final readonly class TelegramBotClient
{
    /** @param string $apiBase https://api.telegram.org, or a local stand-in during end-to-end tests */
    public function __construct(private HttpClientInterface $http, #[Autowire('%env(TELEGRAM_API_BASE)%')] private string $apiBase = 'https://api.telegram.org')
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed> the "result" of the answer
     * @throws TelegramApiException when Telegram answers with an error
     */
    public function call(string $token, string $method, array $payload = []): array
    {
        if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/D', $token) || !preg_match('/^[A-Za-z]{3,40}$/D', $method)) {
            throw new TelegramApiException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.support_chat.invalid_call'));
        }
        try {
            $response = $this->http->request('POST', rtrim($this->apiBase, '/') . '/bot' . $token . '/' . $method, ['json' => $payload, 'timeout' => 8.0]);
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new TelegramApiException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.support_chat.unreachable', ['error' => $this->scrub($e->getMessage(), $token)]));
        }
        if (($data['ok'] ?? false) !== true) {
            throw new TelegramApiException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.support_chat.error', ['error' => (string) ($data['description'] ?? \Commerce\Core\I18n\CanonicalUiText::get('runtime.support_chat.unknown_error'))]));
        }
        $result = $data['result'] ?? [];

        return is_array($result) ? $result : ['value' => $result];
    }

    private function scrub(string $message, string $token): string
    {
        return str_replace($token, '***', $message);
    }
}
