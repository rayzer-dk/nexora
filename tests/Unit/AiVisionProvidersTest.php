<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Ai\Provider\AnthropicMessagesProvider;
use Commerce\Modules\Ai\Provider\GeminiGenerateContentProvider;
use Commerce\Modules\Ai\Provider\OpenAiResponsesProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AiVisionProvidersTest extends TestCase
{
    private const PNG = 'iVBORw0KGgo=';

    public function testAnthropicSendsTheImageBlockBeforeTheText(): void
    {
        $body = [];
        $http = new MockHttpClient(function (string $m, string $url, array $o) use (&$body): MockResponse {
            $body = json_decode($o['body'], true);

            return new MockResponse(json_encode(['content' => [['type' => 'text', 'text' => '{"name":"Mug"}']]]));
        });
        $text = (new AnthropicMessagesProvider($http, true, 'key', 'claude-sonnet-5-5'))->generateFromImage('Describe', 'image/png', self::PNG, 'system');

        self::assertSame('{"name":"Mug"}', $text);
        self::assertSame('image', $body['messages'][0]['content'][0]['type']);
        self::assertSame(['type' => 'base64', 'media_type' => 'image/png', 'data' => self::PNG], $body['messages'][0]['content'][0]['source']);
        self::assertSame('Describe', $body['messages'][0]['content'][1]['text']);
        self::assertSame('system', $body['system']);
    }

    public function testOpenAiSendsADataUrlImage(): void
    {
        $body = [];
        $http = new MockHttpClient(function (string $m, string $url, array $o) use (&$body): MockResponse {
            $body = json_decode($o['body'], true);

            return new MockResponse(json_encode(['output' => [['content' => [['type' => 'output_text', 'text' => 'ok']]]]]));
        });
        $text = (new OpenAiResponsesProvider($http, true, 'key', 'gpt-5'))->generateFromImage('Describe', 'image/jpeg', self::PNG, null);

        self::assertSame('ok', $text);
        self::assertSame('input_image', $body['input'][0]['content'][1]['type']);
        self::assertSame('data:image/jpeg;base64,' . self::PNG, $body['input'][0]['content'][1]['image_url']);
        self::assertFalse($body['store']);
        self::assertArrayNotHasKey('instructions', $body);
    }

    public function testGeminiSendsInlineData(): void
    {
        $body = [];
        $http = new MockHttpClient(function (string $m, string $url, array $o) use (&$body): MockResponse {
            $body = json_decode($o['body'], true);

            return new MockResponse(json_encode(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]]));
        });
        $text = (new GeminiGenerateContentProvider($http, true, 'key', 'gemini-2.5-flash'))->generateFromImage('Describe', 'image/webp', self::PNG, 'sys');

        self::assertSame('ok', $text);
        self::assertSame(['mime_type' => 'image/webp', 'data' => self::PNG], $body['contents'][0]['parts'][1]['inline_data']);
        self::assertSame('sys', $body['systemInstruction']['parts'][0]['text']);
    }

    public function testADisabledProviderRefuses(): void
    {
        $this->expectException(\RuntimeException::class);
        (new AnthropicMessagesProvider(new MockHttpClient(), false, '', ''))->generateFromImage('x', 'image/png', self::PNG);
    }
}
