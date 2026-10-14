<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Provider;

use Commerce\Modules\Ai\Contract\ImageTextGenerationProviderInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OpenAiResponsesProvider implements ImageTextGenerationProviderInterface
{
    public function __construct(private HttpClientInterface $http,private bool $isEnabled,private string $apiKey,private string $model){}
    public function code():string{return 'openai';}
    public function enabled():bool{return $this->isEnabled&&trim($this->apiKey)!==''&&trim($this->model)!=='';}
    public function generate(string $prompt,?string $systemInstruction=null):string
    {
        if(!$this->enabled())throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.527364192fa5'));
        $body=['model'=>trim($this->model),'input'=>$prompt,'store'=>false]+$this->reasoning();if($systemInstruction!==null&&trim($systemInstruction)!=='')$body['instructions']=$systemInstruction;
        $response=$this->http->request('POST','https://api.openai.com/v1/responses',['headers'=>['Authorization'=>'Bearer '.trim($this->apiKey),'Content-Type'=>'application/json'],'json'=>$body,'max_redirects'=>0,'timeout'=>120.0]);
        if($response->getStatusCode()>=300)throw AiHttpError::from($response);$data=$response->toArray(false);self::assertComplete($data);
        $texts=[];foreach((array)($data['output']??[]) as $item){foreach((array)($item['content']??[]) as $part){if(($part['type']??'')==='output_text'&&is_string($part['text']??null))$texts[]=$part['text'];}}
        $text=trim(implode("\n",$texts));if($text==='')throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.fe97d00bf5a8'));return $text;
    }

    public function generateFromImage(string $prompt, string $mimeType, string $base64, ?string $systemInstruction = null): string
    {
        if (!$this->enabled()) {
            throw new RuntimeException('ai_provider_disabled');
        }
        $body = $this->reasoning() + ['model' => trim($this->model), 'store' => false, 'input' => [['role' => 'user', 'content' => [
            ['type' => 'input_text', 'text' => $prompt],
            ['type' => 'input_image', 'image_url' => 'data:' . $mimeType . ';base64,' . $base64],
        ]]]];
        if ($systemInstruction !== null && trim($systemInstruction) !== '') {
            $body['instructions'] = $systemInstruction;
        }
        $response = $this->http->request('POST', 'https://api.openai.com/v1/responses', ['headers' => ['Authorization' => 'Bearer ' . trim($this->apiKey), 'Content-Type' => 'application/json'], 'json' => $body, 'max_redirects' => 0, 'timeout' => 120.0]);
        if ($response->getStatusCode() >= 300) {
            throw AiHttpError::from($response);
        }
        $data = $response->toArray(false);
        self::assertComplete($data);
        $texts = [];
        foreach ((array) ($data['output'] ?? []) as $item) {
            foreach ((array) ($item['content'] ?? []) as $part) {
                if (($part['type'] ?? '') === 'output_text' && is_string($part['text'] ?? null)) {
                    $texts[] = $part['text'];
                }
            }
        }
        $text = trim(implode("\n", $texts));
        if ($text === '') {
            throw new RuntimeException('ai_provider_empty');
        }

        return $text;
    }

    /** Low reasoning effort for the reasoning families (fast enough for writing tasks); other models get no such field. */
    private function reasoning(): array
    {
        $model = strtolower(trim($this->model));

        return preg_match('/^(gpt-5|o3|o4)/', $model) === 1 && !str_contains($model, 'pro') && !str_contains($model, 'chat') ? ['reasoning' => ['effort' => 'low']] : [];
    }

    /** @param array<mixed> $data */
    private static function assertComplete(array $data): void
    {
        if (($data['status'] ?? '') === 'incomplete') {
            throw new RuntimeException('ai_provider_truncated');
        }
    }
}
