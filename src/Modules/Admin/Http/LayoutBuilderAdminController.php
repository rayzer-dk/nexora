<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\Extension\ExtensionContributionRegistry;
use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Appearance\Builder\LayoutRevisionStore;
use Commerce\Modules\Appearance\Builder\LayoutSnippetStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LayoutBuilderAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly LayoutRevisionStore $layouts, private readonly LayoutSnippetStore $snippets, private readonly ExtensionContributionRegistry $extensions)
    {
    }

    #[Route('/admin/appearance/builder/{type}', name: 'admin_appearance_builder', methods: ['GET','POST'], requirements: ['type'=>'home|product|checkout|category'])]
    public function index(Request $request, string $type): Response
    {
        $context = $this->contexts->resolve($request);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('layout_builder_' . $type, (string)$request->request->get('_csrf_token'))) {
                $this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.layoutbuilderadmincontroller.nediisnyi_token_bezpeky_zminy_ne_zastosovano'));
                return $this->redirectToRoute('admin_appearance_builder',['type'=>$type]);
            }
            try {
                $payload = json_decode((string)$request->request->get('layout_json',''), true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.4764b6f78cab'));
                $action=(string)$request->request->get('builder_action','publish');
                if ($action === 'snippet_save') {
                    $blockId=(string)$request->request->get('snippet_block_id','');
                    $block=null;foreach((array)($payload['blocks']??[]) as $candidate){if(is_array($candidate)&&($candidate['id']??null)===$blockId){$block=$candidate;break;}}
                    if(!is_array($block))throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.layoutbuilderadmincontroller.oberit_blok_dlia_zberezhennia'));
                    $this->layouts->saveDraft($context->storeId,$type,$payload,$this->adminId());
                    $this->snippets->save($context->storeId,$type,(string)$request->request->get('snippet_name',''),$block,$this->adminId());
                    $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.layoutbuilderadmincontroller.povtorno_vykorystovuvanu_sektsiiu_zberezheno_chernet'));
                } elseif ($action === 'draft') {
                    $this->layouts->saveDraft($context->storeId,$type,$payload,$this->adminId());
                    $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.layoutbuilderadmincontroller.chernetku_zberezheno_vona_shche_ne_vplyvaie_na_vytry'));
                } else {
                    $this->layouts->publish($context->storeId,$type,$payload,$this->adminId());
                    $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.layoutbuilderadmincontroller.maket_perevireno_ta_opublikovano_poperednia_versiia_'));
                }
            } catch (\Throwable $e) {
                $this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.layoutbuilderadmincontroller.maket_ne_zberezheno_aktyvna_versiia_ne_zminena') . \Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));
            }
            return $this->redirectToRoute('admin_appearance_builder',['type'=>$type]);
        }
        $draft=$this->layouts->draft($context->storeId,$type);
        $layout=$draft ?? $this->layouts->active($context->storeId,$type);
        $missing=[]; foreach((array)($layout['blocks']??[]) as $block){if(is_array($block)&&($block['missing_extension']??false)===true)$missing[]=(string)($block['component']??'');}
        return $this->render('@storefront/admin/appearance/builder.html.twig',[
            'type'=>$type,'layout'=>$layout,'has_draft'=>$draft!==null,'revisions'=>$this->layouts->history($context->storeId,$type),'snippets'=>$this->snippets->all($context->storeId,$type),'extension_components'=>$this->extensions->blocksFor($type),'missing_extension_components'=>array_values(array_unique(array_filter($missing))),
        ]);
    }

    #[Route('/admin/appearance/builder/{type}/rollback/{revisionId}', name: 'admin_appearance_builder_rollback', methods: ['POST'], requirements: ['type'=>'home|product|checkout|category','revisionId'=>'\d+'])]
    public function rollback(Request $request, string $type, int $revisionId): Response
    {
        $context=$this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('layout_builder_rollback_' . $type,(string)$request->request->get('_csrf_token'))) {
            $this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.ai.http.aiadmincontroller.nediisnyi_token_bezpeky'));
            return $this->redirectToRoute('admin_appearance_builder',['type'=>$type]);
        }
        try {
            $this->layouts->rollback($context->storeId,$type,$revisionId,$this->adminId());
            $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.layoutbuilderadmincontroller.maket_vidnovleno_iak_novu_aktyvnu_reviziiu'));
        } catch (\Throwable $e) {
            $this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.appearanceadmincontroller.vidkat_ne_vykonano') . \Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));
        }
        return $this->redirectToRoute('admin_appearance_builder',['type'=>$type]);
    }


    #[Route('/admin/appearance/builder/{type}/snippet/{id}/delete', name: 'admin_appearance_builder_snippet_delete', methods: ['POST'], requirements: ['type'=>'home|product|checkout|category','id'=>'\d+'])]
    public function deleteSnippet(Request $request,string $type,int $id): Response
    {
        $context=$this->contexts->resolve($request);
        if(!$this->isCsrfTokenValid('layout_snippet_delete_'.$id,(string)$request->request->get('_csrf_token'))) throw $this->createAccessDeniedException();
        $this->snippets->delete($context->storeId,$id);
        $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.layoutbuilderadmincontroller.zberezhenu_sektsiiu_vydaleno'));
        return $this->redirectToRoute('admin_appearance_builder',['type'=>$type]);
    }

    private function adminId(): ?int
    {
        $user=$this->getUser();
        return $user instanceof AdminUser ? $user->id : null;
    }
}
