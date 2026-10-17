<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Provider;

use Commerce\Modules\Ai\Contract\ImageTextGenerationProviderInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GeminiGenerateContentProvider implements ImageTextGenerationProviderInterface
{
    public function __construct(private HttpClientInterface $http,private bool $isEnabled,private string $apiKey,private string $model){}
    public function code():string{return 'gemini';}
    public function enabled():bool{return $this->isEnabled&&trim($this->apiKey)!==''&&trim($this->model)!=='';}
    public function generate(string $prompt,?string $systemInstruction=null):string
    {
        if(!$this->enabled())throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.8bcdba48d43d'));
        $body=['contents'=>[['parts'=>[['text'=>$prompt]]]]];if($systemInstruction!==null&&trim($systemInstruction)!=='')$body['systemInstruction']=['parts'=>[['text'=>$systemInstruction]]];
        $url='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode(trim($this->model)).':generateContent';
        $response=$this->http->request('POST',$url,['headers'=>['x-goog-api-key'=>trim($this->apiKey),'Content-Type'=>'application/json'],'json'=>$body,'max_redirects'=>0,'timeout'=>120.0]);
        if($response->getStatusCode()>=300)throw AiHttpError::from($response);$data=$response->toArray(false);self::assertComplete($data);$texts=[];
        foreach((array)($data['candidates'][0]['content']['parts']??[]) as $part){if(is_string($part['text']??null))$texts[]=$part['text'];}$text=trim(implode("\n",$texts));if($text==='')throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d2836cfe50f1'));return $text;
    }

    public function generateFromImage(string $prompt, string $mimeType, string $base64, ?string $systemInstruction = null): string
    {
        if (!$this->enabled()) {
            throw new RuntimeException('ai_provider_disabled');
        }
        $body = ['contents' => [['parts' => [['text' => $prompt], ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64]]]]]];
        if ($systemInstruction !== null && trim($systemInstruction) !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $systemInstruction]]];
        }
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(trim($this->model)) . ':generateContent';
        $response = $this->http->request('POST', $url, ['headers' => ['x-goog-api-key' => trim($this->apiKey), 'Content-Type' => 'application/json'], 'json' => $body, 'max_redirects' => 0, 'timeout' => 120.0]);
        if ($response->getStatusCode() >= 300) {
            throw AiHttpError::from($response);
        }
        $data = $response->toArray(false);
        self::assertComplete($data);
        $texts = [];
        foreach ((array) ($data['candidates'][0]['content']['parts'] ?? []) as $part) {
            if (is_string($part['text'] ?? null)) {
                $texts[] = $part['text'];
            }
        }
        $text = trim(implode("\n", $texts));
        if ($text === '') {
            throw new RuntimeException('ai_provider_empty');
        }

        return $text;
    }

    /** @param array<mixed> $data */
    private static function assertComplete(array $data): void
    {
        $reason = (string) ($data['candidates'][0]['finishReason'] ?? '');
        if ($reason === 'MAX_TOKENS') {
            throw new RuntimeException('ai_provider_truncated');
        }
        $blocked = (string) ($data['promptFeedback']['blockReason'] ?? (in_array($reason, ['SAFETY', 'RECITATION', 'PROHIBITED_CONTENT', 'BLOCKLIST'], true) ? $reason : ''));
        if ($blocked !== '') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.error.ai.gemini.blocked_prefix') . $blocked);
        }
    }
}
