<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\Extension\ExtensionContributionRegistry;
use Commerce\Modules\Admin\Application\ContentLanguageTabs;
use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Appearance\Builder\BuilderComponentCatalog;
use Commerce\Modules\Appearance\Builder\LayoutConflictException;
use Commerce\Modules\Appearance\Builder\LayoutPresence;
use Commerce\Modules\Appearance\Builder\LayoutRevisionStore;
use Commerce\Modules\Appearance\Builder\LayoutSnippetStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LayoutBuilderAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly LayoutRevisionStore $layouts, private readonly LayoutSnippetStore $snippets, private readonly ExtensionContributionRegistry $extensions, private readonly \Commerce\Modules\Appearance\Builder\LayoutPreviewToken $previewToken, private readonly \Doctrine\DBAL\Connection $db, private readonly BuilderComponentCatalog $catalog, private readonly ContentLanguageTabs $languages, private readonly LayoutPresence $presence)
    {
    }

    #[Route('/admin/appearance/builder/{type}', name: 'admin_appearance_builder', methods: ['GET','POST'], requirements: ['type'=>'home|product|checkout|category|cart'])]
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
                $base=$this->baseVersion($request);
                if ($action === 'snippet_save') {
                    $blockId=(string)$request->request->get('snippet_block_id','');
                    $block=null;foreach((array)($payload['blocks']??[]) as $candidate){if(is_array($candidate)&&($candidate['id']??null)===$blockId){$block=$candidate;break;}}
                    if(!is_array($block))throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.layoutbuilderadmincontroller.oberit_blok_dlia_zberezhennia'));
                    $this->layouts->saveDraft($context->storeId,$type,$payload,$this->adminId(),$base);
                    $this->snippets->save($context->storeId,$type,(string)$request->request->get('snippet_name',''),$block,$this->adminId());
                    $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.layoutbuilderadmincontroller.povtorno_vykorystovuvanu_sektsiiu_zberezheno_chernet'));
                } elseif ($action === 'draft') {
                    $this->layouts->saveDraft($context->storeId,$type,$payload,$this->adminId(),$base);
                    $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.layoutbuilderadmincontroller.chernetku_zberezheno_vona_shche_ne_vplyvaie_na_vytry'));
                } else {
                    $this->layouts->publish($context->storeId,$type,$payload,$this->adminId(),$base);
                    $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.layoutbuilderadmincontroller.maket_perevireno_ta_opublikovano_poperednia_versiia_'));
                }
            } catch (LayoutConflictException $e) {
                $this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('admin.builder.conflict_not_saved',$this->conflictText($e)));
            } catch (\Throwable $e) {
                $this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.layoutbuilderadmincontroller.maket_ne_zberezheno_aktyvna_versiia_ne_zminena') . \Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));
            }
            return $this->redirectToRoute('admin_appearance_builder',['type'=>$type]);
        }
        // The version is read before the layout: a save in between then shows as a conflict instead of being overwritten.
        $version=$this->layouts->current($context->storeId,$type)['version'];
        $draft=$this->layouts->draft($context->storeId,$type);
        $layout=$draft ?? $this->layouts->active($context->storeId,$type);
        $missing=[]; foreach((array)($layout['blocks']??[]) as $block){if(is_array($block)&&($block['missing_extension']??false)===true)$missing[]=(string)($block['component']??'');}
        $components=$this->catalog->components($type);
        $fields=array_column($components,'fields','component');
        $extensionComponents=$this->extensions->blocksFor($type);
        $schemaErrors=[];
        foreach($extensionComponents as $block){
            $id=(string)($block['id']??'');
            $own=$this->extensions->blockFields($id);
            if($own===null){$schemaErrors[$id]=(string)($block['extension_code']??'');}else{$fields[$id]=$this->catalog->withCommonFields($own);}
        }
        return $this->render('@storefront/admin/appearance/builder.html.twig',[
            'builder_components'=>$components,'builder_fields'=>$fields,'builder_common_fields'=>$this->catalog->commonFields(),'builder_schema_errors'=>$schemaErrors,
            'builder_locales'=>array_map(static fn(array $tab): array => ['code'=>$tab['code'],'name'=>$tab['name']],$this->languages->tabs($context->storeId,$context->locale)),
            'preview_url'=>$this->previewUrl($type,$context->storeId,$context->locale),
            'type'=>$type,'layout'=>$layout,'layout_version'=>$version,'has_draft'=>$draft!==null,'revisions'=>$this->layouts->history($context->storeId,$type),'snippets'=>$this->snippets->all($context->storeId,$type),'extension_components'=>$extensionComponents,'missing_extension_components'=>array_values(array_unique(array_filter($missing))),
        ]);
    }

    /** Autosave of the builder: the draft is stored without leaving the page; a fresh preview link keeps the preview past the token lifetime. */
    #[Route('/admin/appearance/builder/{type}/draft.json', name: 'admin_appearance_builder_draft_json', methods: ['POST'], requirements: ['type'=>'home|product|checkout|category|cart'])]
    public function draftJson(Request $request, string $type): JsonResponse
    {
        return $this->writeJson($request, $type, false);
    }

    /** Publication from the builder page; a newer version saved meanwhile is answered with 409 instead of being replaced. */
    #[Route('/admin/appearance/builder/{type}/publish.json', name: 'admin_appearance_builder_publish_json', methods: ['POST'], requirements: ['type'=>'home|product|checkout|category|cart'])]
    public function publishJson(Request $request, string $type): JsonResponse
    {
        return $this->writeJson($request, $type, true);
    }

    /** Every open builder page reports here every 30 s and on leaving (`leave=1`); the answer lists the other editors. */
    #[Route('/admin/appearance/builder/{type}/presence.json', name: 'admin_appearance_builder_presence', methods: ['POST'], requirements: ['type'=>'home|product|checkout|category|cart'])]
    public function presence(Request $request, string $type): JsonResponse
    {
        $context = $this->contexts->resolve($request);
        $adminId = $this->adminId();
        if ($adminId === null || !$this->isCsrfTokenValid('layout_builder_' . $type, (string) $request->request->get('_csrf_token'))) {
            return new JsonResponse(['ok' => false, 'error' => 'csrf', 'message' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.ai.http.aiadmincontroller.nediisnyi_token_bezpeky')], 403);
        }
        if ($request->request->getBoolean('leave')) {
            $this->presence->leave($context->storeId, $type, $adminId);

            return new JsonResponse(['ok' => true, 'others' => []]);
        }

        return new JsonResponse(['ok' => true, 'others' => $this->presence->touch($context->storeId, $type, $adminId)]);
    }

    private function writeJson(Request $request, string $type, bool $publish): JsonResponse
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('layout_builder_' . $type, (string) $request->request->get('_csrf_token'))) {
            return new JsonResponse(['ok' => false, 'error' => 'csrf', 'message' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.ai.http.aiadmincontroller.nediisnyi_token_bezpeky')], 403);
        }
        $base = $this->baseVersion($request);
        $force = $request->request->getBoolean('force');
        try {
            $payload = json_decode((string) $request->request->get('layout_json', ''), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.4764b6f78cab'));
            }
            $version = $publish
                ? $this->layouts->publish($context->storeId, $type, $payload, $this->adminId(), $base, $force)
                : $this->layouts->saveDraft($context->storeId, $type, $payload, $this->adminId(), $base, $force);
        } catch (LayoutConflictException $e) {
            return new JsonResponse(['ok' => false, 'error' => 'conflict', 'version' => $e->currentVersion, 'saved_by_name' => $this->adminName($e->savedBy), 'saved_at' => $e->savedAt], 409);
        } catch (\JsonException|\RuntimeException $e) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid', 'message' => $e->getMessage()], 422);
        }
        if ($publish) {
            // The page reloads after publishing (fresh history table, new undo history) and shows this message.
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.layoutbuilderadmincontroller.maket_perevireno_ta_opublikovano_poperednia_versiia_'));
        }

        return new JsonResponse(['ok' => true, 'version' => $version, 'saved_at' => gmdate(DATE_ATOM), 'preview_url' => $this->previewUrl($type, $context->storeId, $context->locale)]);
    }

    #[Route('/admin/appearance/builder/{type}/rollback/{revisionId}', name: 'admin_appearance_builder_rollback', methods: ['POST'], requirements: ['type'=>'home|product|checkout|category|cart','revisionId'=>'\d+'])]
    public function rollback(Request $request, string $type, int $revisionId): Response
    {
        $context=$this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('layout_builder_rollback_' . $type,(string)$request->request->get('_csrf_token'))) {
            $this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.ai.http.aiadmincontroller.nediisnyi_token_bezpeky'));
            return $this->redirectToRoute('admin_appearance_builder',['type'=>$type]);
        }
        try {
            $this->layouts->rollback($context->storeId,$type,$revisionId,$this->adminId(),$this->baseVersion($request));
            $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.layoutbuilderadmincontroller.maket_vidnovleno_iak_novu_aktyvnu_reviziiu'));
        } catch (LayoutConflictException $e) {
            $this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('admin.builder.conflict_not_restored',$this->conflictText($e)));
        } catch (\Throwable $e) {
            $this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.appearanceadmincontroller.vidkat_ne_vykonano') . \Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));
        }
        return $this->redirectToRoute('admin_appearance_builder',['type'=>$type]);
    }


    #[Route('/admin/appearance/builder/{type}/snippet/{id}/delete', name: 'admin_appearance_builder_snippet_delete', methods: ['POST'], requirements: ['type'=>'home|product|checkout|category|cart','id'=>'\d+'])]
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

    /** The version the page was opened with; requests without one (older forms, API callers) keep last-write-wins. */
    private function baseVersion(Request $request): ?int
    {
        $base = (string) $request->request->get('base_version', '');

        return ctype_digit($base) ? (int) $base : null;
    }

    private function adminName(?int $adminId): string
    {
        $name = $adminId === null ? false : $this->db->fetchOne("SELECT COALESCE(NULLIF(display_name, ''), email) FROM mc_admin_user WHERE id=?", [$adminId]);

        return is_string($name) && $name !== '' ? $name : \Commerce\Core\I18n\CanonicalUiText::get('admin.builder.someone');
    }

    /** @return array{name:string,time:string} */
    private function conflictText(LayoutConflictException $e): array
    {
        return ['name' => $this->adminName($e->savedBy), 'time' => (new \DateTimeImmutable($e->savedAt))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('H:i')];
    }

    /** A real page of the storefront that uses this layout, signed to show the draft. */
    private function previewUrl(string $type, int $storeId, string $locale): string
    {
        $path = $this->previewPath($type, $storeId, $locale);

        return $path . (str_contains($path, '?') ? '&' : '?') . '_layout_preview=' . rawurlencode($this->previewToken->issue($storeId, $type));
    }

    private function previewPath(string $type, int $storeId, string $locale): string
    {
        $entity = match ($type) {'product' => 'product', 'category' => 'category', default => ''};
        if ($entity !== '') {
            $path = $this->db->fetchOne('SELECT path FROM mc_seo_route WHERE store_id=? AND locale=? AND entity_type=? AND indexable=1 ORDER BY id LIMIT 1', [$storeId, $locale, $entity]);
            if (is_string($path) && $path !== '') {
                return '/' . ltrim($path, '/');
            }
        }

        return match ($type) {'cart' => '/cart', 'checkout' => '/checkout', default => '/'};
    }
}
