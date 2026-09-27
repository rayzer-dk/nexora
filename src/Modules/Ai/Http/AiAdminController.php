<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Http;

use Commerce\Modules\Ai\Application\AiProviderRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AiAdminController extends AbstractController
{
    public function __construct(private readonly AiProviderRegistry $providers){}

    #[Route('/admin/api/ai/product-draft',name:'admin_ai_product_draft',methods:['POST'])]
    public function productDraft(Request $request):JsonResponse
    {
        if(!$this->isCsrfTokenValid('admin_ai_product',(string)$request->request->get('_token')))return $this->json(['ok'=>false,'message'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.ai.http.aiadmincontroller.nediisnyi_token_bezpeky')],403);
        try{$provider=$this->providers->require((string)$request->request->get('provider'));$name=trim((string)$request->request->get('name'));$sku=trim((string)$request->request->get('sku'));$current=trim((string)$request->request->get('description'));if($name==='')throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.ai.http.aiadmincontroller.spochatku_vkazhit_nazvu_tovaru'));
            $prompt=\Commerce\Core\I18n\CanonicalUiText::get('ai.product_draft.prompt',['name'=>$name,'sku'=>$sku,'current'=>$current]);
            $raw=$provider->generate($prompt,\Commerce\Core\I18n\CanonicalUiText::get('ai.product_draft.system'));$raw=trim(preg_replace('/^```(?:json)?\s*|\s*```$/u','',$raw)??$raw);$data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($data)||!is_string($data['short_description']??null)||!is_string($data['description']??null))throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ccaa8cd0be5e'));
            return $this->json(['ok'=>true,'short_description'=>mb_substr(trim($data['short_description']),0,2000),'description'=>trim($data['description'])]);
        }catch(\Throwable $e){return $this->json(['ok'=>false,'message'=>$e instanceof \DomainException?$e->getMessage():\Commerce\Core\I18n\CanonicalUiText::get('ai.product_draft.failed')],422);}
    }
}
