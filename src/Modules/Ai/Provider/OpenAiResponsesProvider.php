<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Provider;

use Commerce\Modules\Ai\Contract\TextGenerationProviderInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OpenAiResponsesProvider implements TextGenerationProviderInterface
{
    public function __construct(private HttpClientInterface $http,private bool $isEnabled,private string $apiKey,private string $model){}
    public function code():string{return 'openai';}
    public function enabled():bool{return $this->isEnabled&&trim($this->apiKey)!==''&&trim($this->model)!=='';}
    public function generate(string $prompt,?string $systemInstruction=null):string
    {
        if(!$this->enabled())throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.527364192fa5'));
        $body=['model'=>trim($this->model),'input'=>$prompt,'store'=>false];if($systemInstruction!==null&&trim($systemInstruction)!=='')$body['instructions']=$systemInstruction;
        $response=$this->http->request('POST','https://api.openai.com/v1/responses',['headers'=>['Authorization'=>'Bearer '.trim($this->apiKey),'Content-Type'=>'application/json'],'json'=>$body,'max_redirects'=>0,'timeout'=>30.0]);
        if($response->getStatusCode()>=300)throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0974916cd615'));$data=$response->toArray(false);
        $texts=[];foreach((array)($data['output']??[]) as $item){foreach((array)($item['content']??[]) as $part){if(($part['type']??'')==='output_text'&&is_string($part['text']??null))$texts[]=$part['text'];}}
        $text=trim(implode("\n",$texts));if($text==='')throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.fe97d00bf5a8'));return $text;
    }
}
