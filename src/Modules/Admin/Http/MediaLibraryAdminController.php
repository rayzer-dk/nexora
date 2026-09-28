<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Media\Application\MediaImageService;
use Commerce\Modules\Media\Application\MediaLibraryService;
use Commerce\Modules\Media\Application\MediaVideoService;
use Commerce\Modules\Media\Application\MediaMetadata;
use Commerce\Modules\Media\Application\MediaMetadataService;
use Commerce\Core\Configuration\ConfigurationRevisionStore;
use Commerce\Modules\Admin\Domain\AdminUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MediaLibraryAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly MediaLibraryService $library,
        private readonly MediaImageService $images,
        private readonly MediaVideoService $videos,
        private readonly MediaMetadataService $metadata,
        private readonly ConfigurationRevisionStore $revisions,
    ) {}

    #[Route('/admin/media', name:'admin_media_library', methods:['GET'])]
    public function index(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request); $folder=$this->optionalInt($request->query->get('folder'));
        $result=$this->library->search($ctx->storeId,(string)$request->query->get('q',''),$folder,(int)$request->query->get('page',1),48);
        return $this->render('@storefront/admin/media/library.html.twig',['result'=>$result,'folders'=>$this->library->folders($ctx->storeId),'folder'=>$folder,'query'=>(string)$request->query->get('q',''),'processing'=>$this->processing($ctx->storeId)]);
    }

    #[Route('/admin/media.json', name:'admin_media_library_json', methods:['GET'])]
    public function libraryJson(Request $request): JsonResponse
    {
        $ctx=$this->contexts->resolve($request); $result=$this->library->search($ctx->storeId,(string)$request->query->get('q',''),$this->optionalInt($request->query->get('folder')),1,72);
        return $this->json($result);
    }

    #[Route('/admin/media/upload', name:'admin_media_upload', methods:['POST'])]
    public function upload(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request); $this->csrf($request,'media_upload'); $files=$request->files->all('files'); if (!is_array($files)) $files=[];
        $folder=$this->optionalInt($request->request->get('folder_id')); $count=0;
        try { foreach($files as $file){ if(!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) continue; $mime=strtolower((string)$file->getMimeType()); $saved=str_starts_with($mime,'video/')?$this->videos->upload($file):$this->images->upload($file,$ctx->storeId); $this->metadata->save($saved->assetId,new MediaMetadata($folder)); $count++; } $this->addFlash('success',$count.\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.medialibraryadmincontroller.fail_iv_zavantazheno_zobrazhennia_optymizovano_video')); }
        catch(\Throwable $e){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.medialibraryadmincontroller.zavantazhennia_zupyneno').\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));}
        return $this->redirectToRoute('admin_media_library',$folder?['folder'=>$folder]:[]);
    }


    #[Route('/admin/media/settings', name:'admin_media_settings', methods:['POST'])]
    public function settings(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request); $this->csrf($request,'media_settings');
        $widths=array_map('intval',(array)$request->request->all('widths'));
        $user=$this->getUser(); $actor=$user instanceof AdminUser?'admin:'.$user->id:'admin';
        try{
            $this->revisions->activateStoreJson($ctx->storeId,'media','image_processing',[
                'format'=>(string)$request->request->get('format','original'),
                'widths'=>$widths,
                'include_original'=>$request->request->has('include_original'),
                'quality'=>max(35,min(95,(int)$request->request->get('quality',85))),
            ],$actor);
            $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('flash.image_settings_saved'));
        }catch(\Throwable){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));}
        return $this->redirectToRoute('admin_media_library');
    }

    #[Route('/admin/media/folder', name:'admin_media_folder_create', methods:['POST'])]
    public function folder(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request); $this->csrf($request,'media_folder_create');
        try{$this->library->createFolder($ctx->storeId,(string)$request->request->get('name',''),$this->optionalInt($request->request->get('parent_id')));$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.medialibraryadmincontroller.papku_stvoreno'));}catch(\Throwable $e){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));}
        return $this->redirectToRoute('admin_media_library');
    }

    #[Route('/admin/media/{assetId}/metadata', name:'admin_media_metadata', methods:['POST'], requirements:['assetId'=>'\\d+'])]
    public function metadata(Request $request,int $assetId): Response
    {
        $this->contexts->resolve($request); $this->csrf($request,'media_metadata_'.$assetId);
        $tags=array_values(array_filter(array_map('trim',explode(',',(string)$request->request->get('tags','')))));
        try{$this->metadata->save($assetId,new MediaMetadata($this->optionalInt($request->request->get('folder_id')),(string)$request->request->get('alt_text',''),(string)$request->request->get('title',''),(float)$request->request->get('focal_x',50),(float)$request->request->get('focal_y',50),$tags));$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.medialibraryadmincontroller.metadani_zberezheno'));}catch(\Throwable $e){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));}
        return $this->redirectToRoute('admin_media_library');
    }

    #[Route('/admin/media/{assetId}/delete', name:'admin_media_delete', methods:['POST'], requirements:['assetId'=>'\\d+'])]
    public function delete(Request $request,int $assetId): Response
    {
        $this->contexts->resolve($request); $this->csrf($request,'media_delete_'.$assetId);
        try{$this->library->delete($assetId);$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.medialibraryadmincontroller.fail_vydaleno_z_biblioteky'));}catch(\Throwable $e){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));}
        return $this->redirectToRoute('admin_media_library');
    }


    /** @return array{format:string,widths:list<int>,include_original:bool,quality:int} */
    private function processing(int $storeId): array
    {
        $input=$this->revisions->latestValidPayload($storeId,'media','image_processing'); $input=is_array($input)?$input:[];
        $format=strtolower(trim((string)($input['format']??'original'))); if(!in_array($format,['original','jpeg','png','webp','avif'],true))$format='original';
        $allowed=[320,640,960,1280,1920]; $widths=array_values(array_unique(array_filter(array_map('intval',(array)($input['widths']??[640,960,1280])),static fn(int $w):bool=>in_array($w,$allowed,true)))); sort($widths);
        return ['format'=>$format,'widths'=>$widths,'include_original'=>(bool)($input['include_original']??true),'quality'=>max(35,min(95,(int)($input['quality']??85)))];
    }

    private function optionalInt(mixed $value): ?int { $v=(int)$value; return $v>0?$v:null; }
    private function csrf(Request $request,string $id): void { if(!$this->isCsrfTokenValid($id,(string)$request->request->get('_csrf_token'))) throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf')); }
}
