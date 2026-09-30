<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Provider;

use Commerce\Modules\Ai\Contract\TextGenerationProviderInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class AnthropicMessagesProvider implements TextGenerationProviderInterface
{
    public function __construct(private HttpClientInterface $http, private bool $isEnabled, private string $apiKey, private string $model)
    {
    }

    public function code(): string
    {
        return 'anthropic';
    }

    public function enabled(): bool
    {
        return $this->isEnabled && trim($this->apiKey) !== '' && trim($this->model) !== '';
    }

    public function generate(string $prompt, ?string $systemInstruction = null): string
    {
        if (!$this->enabled()) {
            throw new RuntimeException('ai_provider_disabled');
        }
        $body = ['model' => trim($this->model), 'max_tokens' => 4096, 'messages' => [['role' => 'user', 'content' => $prompt]]];
        if ($systemInstruction !== null && trim($systemInstruction) !== '') {
            $body['system'] = $systemInstruction;
        }
        $response = $this->http->request('POST', 'https://api.anthropic.com/v1/messages', [
            'headers' => ['x-api-key' => trim($this->apiKey), 'anthropic-version' => '2023-06-01', 'Content-Type' => 'application/json'],
            'json' => $body, 'max_redirects' => 0, 'timeout' => 45.0,
        ]);
        if ($response->getStatusCode() >= 300) {
            throw new RuntimeException('ai_provider_http_' . $response->getStatusCode());
        }
        $texts = [];
        foreach ((array) ($response->toArray(false)['content'] ?? []) as $part) {
            if (($part['type'] ?? '') === 'text' && is_string($part['text'] ?? null)) {
                $texts[] = $part['text'];
            }
        }
        $text = trim(implode("\n", $texts));
        if ($text === '') {
            throw new RuntimeException('ai_provider_empty');
        }

        return $text;
    }
}
