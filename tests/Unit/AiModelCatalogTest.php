<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Security\SecretVault;
use Commerce\Modules\Ai\Application\AiModelCatalog;
use Commerce\Modules\Ai\Application\AiModelCatalogException;
use Commerce\Modules\Ai\Application\AiSettings;
use Commerce\Tests\Unit\Support\ArraySystemSettingStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AiModelCatalogTest extends TestCase
{
    private Connection $db;
    private ArraySystemSettingStore $store;
    private AiSettings $settings;
    private SecretVault $vault;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('CREATE TABLE mc_ai_provider_config (store_id INT, provider TEXT, enabled INT, model TEXT, api_key_enc TEXT, updated_at TEXT, PRIMARY KEY (store_id, provider))');
        $this->store = new ArraySystemSettingStore();
        $this->vault = new SecretVault(str_repeat('k', 32));
        $this->settings = new AiSettings($this->db, $this->vault, ['openai' => ['enabled' => false, 'key' => 'sk-test-0123456789', 'model' => ''], 'gemini' => ['enabled' => false, 'key' => 'gem-test-0123456789', 'model' => ''], 'anthropic' => ['enabled' => false, 'key' => '', 'model' => '']]);
    }

    private function catalog(callable|array $responses): AiModelCatalog
    {
        return new AiModelCatalog($this->settings, new MockHttpClient($responses), $this->store);
    }

    /** @return array<string,mixed> */
    private static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/Fixtures/ai/' . $name), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testOpenAiListIsFilteredToTextModelsAndCached(): void
    {
        $seen = [];
        $catalog = $this->catalog(function (string $method, string $url, array $options) use (&$seen) {
            $seen = [$method, $url, $options['headers'] ?? []];

            return new MockResponse((string) file_get_contents(__DIR__ . '/Fixtures/ai/openai-models.json'), ['http_code' => 200]);
        });
        $models = $catalog->refresh(1, 'openai');
        self::assertSame(['o3', 'gpt-5', 'gpt-4o', 'gpt-4.1'], $models);
        self::assertSame('GET', $seen[0]);
        self::assertSame('https://api.openai.com/v1/models', $seen[1]);
        self::assertContains('Authorization: Bearer sk-test-0123456789', $seen[2]);
        $options = $catalog->options(1, 'openai', 'gpt-5');
        self::assertTrue($options['refreshed']);
        self::assertSame($models, $options['models']);
        self::assertNotNull($options['fetched_at']);
    }

    public function testGeminiKeepsOnlyGenerateContentModelsWithoutPrefix(): void
    {
        $models = $this->catalog(fn () => new MockResponse((string) file_get_contents(__DIR__ . '/Fixtures/ai/gemini-models.json')))->refresh(1, 'gemini');
        self::assertSame(['gemini-2.5-pro', 'gemini-2.5-flash'], $models);
    }

    public function testAnthropicListAndHeaders(): void
    {
        $this->db->executeStatement("INSERT INTO mc_ai_provider_config (store_id,provider,enabled,model,api_key_enc,updated_at) VALUES (1,'anthropic',1,'claude-sonnet-5-5',?,'x')", [$this->vault->encrypt('sk-ant-0123456789abcdef', 'ai.key')]);
        $headers = [];
        $catalog = $this->catalog(function (string $m, string $url, array $o) use (&$headers) {
            $headers = $o['headers'];

            return new MockResponse((string) file_get_contents(__DIR__ . '/Fixtures/ai/anthropic-models.json'));
        });
        self::assertSame(['claude-sonnet-5-5', 'claude-haiku-4-5'], $catalog->refresh(1, 'anthropic'));
        self::assertContains('x-api-key: sk-ant-0123456789abcdef', $headers);
        self::assertContains('anthropic-version: 2023-06-01', $headers);
    }

    public function testBuiltInListIsUsedUntilRefreshedAndCurrentModelIsAlwaysPresent(): void
    {
        $options = $this->catalog([])->options(1, 'openai', 'my-custom-model');
        self::assertFalse($options['refreshed']);
        self::assertSame('my-custom-model', $options['models'][0]);
        self::assertContains('gpt-5', $options['models']);
    }

    /** @return iterable<string,array{int,string}> */
    public static function failures(): iterable
    {
        yield 'bad key' => [401, 'auth'];
        yield 'forbidden' => [403, 'auth'];
        yield 'rate limit' => [429, 'rate_limit'];
        yield 'server error' => [502, 'http'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testHttpFailuresMapToCodesAndKeepTheCache(int $status, string $code): void
    {
        $this->store->setArray('ai.models.openai.1', ['models' => ['gpt-5'], 'fetched_at' => '2026-01-01 00:00:00']);
        try {
            $this->catalog(fn () => new MockResponse('{}', ['http_code' => $status]))->refresh(1, 'openai');
            self::fail('exception expected');
        } catch (AiModelCatalogException $e) {
            self::assertSame($code, $e->getMessage());
        }
        self::assertSame(['gpt-5'], $this->catalog([])->options(1, 'openai')['models']);
    }

    public function testTransportErrorAndEmptyAndNoKey(): void
    {
        $net = $this->catalog(fn () => new MockResponse('', ['error' => 'timeout']));
        try {
            $net->refresh(1, 'openai');
            self::fail();
        } catch (AiModelCatalogException $e) {
            self::assertSame('network', $e->getMessage());
        }
        try {
            $this->catalog(fn () => new MockResponse('{"data":[{"id":"text-embedding-3-small"}]}'))->refresh(1, 'openai');
            self::fail();
        } catch (AiModelCatalogException $e) {
            self::assertSame('empty', $e->getMessage());
        }
        try {
            $this->catalog([])->refresh(1, 'anthropic');
            self::fail();
        } catch (AiModelCatalogException $e) {
            self::assertSame('no_key', $e->getMessage());
        }
        try {
            $this->catalog([])->refresh(1, 'nope');
            self::fail();
        } catch (AiModelCatalogException $e) {
            self::assertSame('unknown_provider', $e->getMessage());
        }
    }
}
