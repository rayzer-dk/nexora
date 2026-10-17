<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Ai\Provider\AnthropicMessagesProvider;
use Commerce\Modules\Ai\Provider\GeminiGenerateContentProvider;
use Commerce\Modules\Ai\Provider\OpenAiResponsesProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** A cut-off answer must be reported as such (not as "unexpected format"), and long answers must have room. */
final class AiProviderLimitsTest extends TestCase
{
    public function testAnthropicAsksForRoomAndReportsACutOffAnswer(): void
    {
        $body = [];
        $http = new MockHttpClient(function (string $m, string $url, array $o) use (&$body): MockResponse {
            $body = json_decode($o['body'], true);

            return new MockResponse(json_encode(['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => '{"text":"half']]]));
        });
        try {
            (new AnthropicMessagesProvider($http, true, 'key', 'claude-sonnet-5-5'))->generate('Translate', 'system');
            self::fail('expected a truncation error');
        } catch (\RuntimeException $e) {
            self::assertSame('ai_provider_truncated', $e->getMessage());
        }
        self::assertGreaterThanOrEqual(16000, $body['max_tokens']);
    }

    public function testOpenAiReasoningModelsThinkBrieflyAndIncompleteIsReported(): void
    {
        $bodies = [];
        $http = new MockHttpClient(function (string $m, string $url, array $o) use (&$bodies): MockResponse {
            $bodies[] = json_decode($o['body'], true);

            return new MockResponse(json_encode(['status' => 'incomplete', 'output' => [['content' => [['type' => 'output_text', 'text' => '{"te']]]]]));
        });
        foreach (['gpt-5', 'gpt-4o'] as $model) {
            try {
                (new OpenAiResponsesProvider($http, true, 'key', $model))->generate('x', null);
                self::fail('expected a truncation error');
            } catch (\RuntimeException $e) {
                self::assertSame('ai_provider_truncated', $e->getMessage());
            }
        }
        self::assertSame(['effort' => 'low'], $bodies[0]['reasoning']);
        self::assertArrayNotHasKey('reasoning', $bodies[1], 'non-reasoning models reject the field');
    }

    public function testGeminiReportsMaxTokensAndBlockedAnswers(): void
    {
        $answers = [
            ['candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => '{"te']]]]]],
            ['promptFeedback' => ['blockReason' => 'SAFETY'], 'candidates' => []],
        ];
        $http = new MockHttpClient(function () use (&$answers): MockResponse {
            return new MockResponse(json_encode(array_shift($answers)));
        });
        $provider = new GeminiGenerateContentProvider($http, true, 'key', 'gemini-2.5-flash');
        foreach (['ai_provider_truncated', CanonicalUiText::get('runtime.error.ai.gemini.blocked_prefix') . 'SAFETY'] as $expected) {
            try {
                $provider->generate('x', null);
                self::fail('expected ' . $expected);
            } catch (\RuntimeException $e) {
                self::assertSame($expected, $e->getMessage());
            }
        }
    }
}
