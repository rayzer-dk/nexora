<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Application;

use Commerce\Core\Configuration\SystemSettingStore;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Model lists per AI provider. "Refresh" asks the provider's own models endpoint with the saved key and keeps the
 * answer (with a timestamp) in the system settings; until then, or when the provider cannot be reached, a built-in
 * list of well-known models is offered. The key is only sent to the provider's official host.
 */
final class AiModelCatalog
{
    /** Well-known models, used before the first refresh and as a fallback. */
    private const BUILT_IN = [
        'openai' => ['gpt-5', 'gpt-5-mini', 'gpt-5-nano', 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4o', 'gpt-4o-mini'],
        'gemini' => ['gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.0-flash'],
        'anthropic' => ['claude-opus-4-5', 'claude-sonnet-5-5', 'claude-sonnet-4-5', 'claude-haiku-4-5'],
    ];
    private const MAX_MODELS = 300;
    private const TIMEOUT = 15.0;

    public function __construct(private readonly AiSettings $settings, private readonly HttpClientInterface $http, private readonly SystemSettingStore $store)
    {
    }

    /** @return list<string> */
    public function builtIn(string $provider): array
    {
        return self::BUILT_IN[$provider] ?? [];
    }

    /**
     * Models to show in the select: the refreshed list when present, otherwise the built-in one; the currently
     * configured model is always part of it so saving the form never silently changes it.
     *
     * @return array{models:list<string>,fetched_at:?string,refreshed:bool}
     */
    public function options(int $storeId, string $provider, string $current = ''): array
    {
        $cache = $this->store->getArray($this->key($storeId, $provider));
        $cached = is_array($cache['models'] ?? null) ? array_values(array_filter($cache['models'], 'is_string')) : [];
        $models = $cached !== [] ? $cached : $this->builtIn($provider);
        $current = trim($current);
        if ($current !== '' && !in_array($current, $models, true)) {
            array_unshift($models, $current);
        }

        return ['models' => $models, 'fetched_at' => $cached !== [] && is_string($cache['fetched_at'] ?? null) ? (string) $cache['fetched_at'] : null, 'refreshed' => $cached !== []];
    }

    /**
     * Fetches, filters, stores and returns the provider's model ids.
     *
     * @return list<string>
     * @throws AiModelCatalogException with a machine-readable code: unknown_provider, no_key, auth, rate_limit, http, network, empty
     */
    public function refresh(int $storeId, string $provider): array
    {
        if (!isset(AiSettings::PROVIDERS[$provider])) {
            throw new AiModelCatalogException('unknown_provider');
        }
        $key = trim($this->settings->providers($storeId)[$provider]['key'] ?? '');
        if ($key === '') {
            throw new AiModelCatalogException('no_key');
        }
        [$url, $headers] = match ($provider) {
            'openai' => ['https://api.openai.com/v1/models', ['Authorization' => 'Bearer ' . $key]],
            'anthropic' => ['https://api.anthropic.com/v1/models?limit=100', ['x-api-key' => $key, 'anthropic-version' => '2023-06-01']],
            default => ['https://generativelanguage.googleapis.com/v1beta/models?pageSize=200', ['x-goog-api-key' => $key]],
        };
        try {
            $response = $this->http->request('GET', $url, ['headers' => $headers + ['Accept' => 'application/json'], 'max_redirects' => 0, 'timeout' => self::TIMEOUT]);
            $status = $response->getStatusCode();
            if ($status === 401 || $status === 403 || ($provider === 'gemini' && $status === 400)) {
                throw new AiModelCatalogException('auth');
            }
            if ($status === 429) {
                throw new AiModelCatalogException('rate_limit');
            }
            if ($status >= 300) {
                throw new AiModelCatalogException('http');
            }
            $data = $response->toArray(false);
        } catch (AiModelCatalogException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new AiModelCatalogException('network');
        }
        $models = self::parse($provider, $data);
        if ($models === []) {
            throw new AiModelCatalogException('empty');
        }
        $this->store->setArray($this->key($storeId, $provider), ['models' => $models, 'fetched_at' => gmdate('Y-m-d H:i:s')]);

        return $models;
    }

    /**
     * Extracts usable text-generation model ids from a provider response.
     *
     * @param array<string,mixed> $data
     * @return list<string>
     */
    public static function parse(string $provider, array $data): array
    {
        $ids = [];
        if ($provider === 'gemini') {
            foreach ((array) ($data['models'] ?? []) as $m) {
                if (!is_array($m) || !is_string($m['name'] ?? null)) {
                    continue;
                }
                $methods = is_array($m['supportedGenerationMethods'] ?? null) ? $m['supportedGenerationMethods'] : ['generateContent'];
                if (in_array('generateContent', $methods, true)) {
                    $ids[] = preg_replace('#^models/#', '', $m['name']) ?? '';
                }
            }
        } else {
            foreach ((array) ($data['data'] ?? []) as $m) {
                if (is_array($m) && is_string($m['id'] ?? null)) {
                    $ids[] = $m['id'];
                }
            }
        }
        $clean = [];
        foreach ($ids as $id) {
            $id = trim($id);
            if (preg_match('/^[A-Za-z0-9._:\-\/]{2,80}$/', $id) !== 1) {
                continue;
            }
            if ($provider === 'openai' && (preg_match('/^(gpt-|o\d|chatgpt-)/', $id) !== 1 || preg_match('/(embed|whisper|tts|audio|realtime|transcribe|moderation|image|dall-e|davinci|babbage|search|diarize)/', $id) === 1)) {
                continue;
            }
            if ($provider === 'gemini' && preg_match('/(embedding|aqa|imagen|veo|tts|image|live|native-audio)/', $id) === 1) {
                continue;
            }
            $clean[$id] = true;
        }
        $list = array_keys($clean);
        usort($list, static fn (string $a, string $b): int => strnatcasecmp($b, $a));

        return array_slice($list, 0, self::MAX_MODELS);
    }

    private function key(int $storeId, string $provider): string
    {
        return 'ai.models.' . $provider . '.' . $storeId;
    }
}
