<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Application;

use Commerce\Modules\Ai\Contract\TextGenerationProviderInterface;

final class AiProviderRegistry
{
    /** @var array<string,TextGenerationProviderInterface> */
    private array $providers=[];
    public function __construct(iterable $providers){foreach($providers as $provider){$this->register($provider);}}
    public function register(TextGenerationProviderInterface $provider):void{$code=trim($provider->code());if($code===''||isset($this->providers[$code]))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('extension.sdk.ai_provider_duplicate') . $code);$this->providers[$code]=$provider;}
    /** @return list<string> */ public function enabledCodes():array{return array_values(array_keys(array_filter($this->providers,static fn($p)=>$p->enabled())));}
    public function require(string $code):TextGenerationProviderInterface{$p=$this->providers[$code]??null;if(!$p||!$p->enabled())throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.794607124bae'));return $p;}
}
