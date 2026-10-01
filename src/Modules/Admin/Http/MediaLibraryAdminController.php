<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Media\Application\MediaImageService;
use Commerce\Modules\Media\Application\MediaLibraryService;
use Commerce\Modules\Media\Application\MediaVideoService;
use Commerce\Modules\Media\Application\MediaImageProfile;
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
        $ctx=$this->contexts->resolve($request); $folder=$this->folderFilter($request->query->get('folder'));
        $result=$this->library->search($ctx->storeId,(string)$request->query->get('q',''),$folder,(int)$request->query->get('page',1),48);
        return $this->render('@storefront/admin/media/library.html.twig',['result'=>$result,'folders'=>$this->library->folders($ctx->storeId),'counts'=>$this->library->folderCounts($ctx->storeId),'folder'=>$folder,'query'=>(string)$request->query->get('q',''),'processing'=>$this->processing($ctx->storeId)]);
    }

    #[Route('/admin/media.json', name:'admin_media_library_json', methods:['GET'])]
    public function libraryJson(Request $request): JsonResponse
    {
        $ctx=$this->contexts->resolve($request); $result=$this->library->search($ctx->storeId,(string)$request->query->get('q',''),$this->folderFilter($request->query->get('folder')),max(1,(int)$request->query->get('page',1)),72);
        $result['folders']=$this->library->folders($ctx->storeId); $result['counts']=$this->library->folderCounts($ctx->storeId);
        return $this->json($result);
    }

    #[Route('/admin/media/upload', name:'admin_media_upload', methods:['POST'])]
    public function upload(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request); $this->csrf($request,'media_upload'); $files=$request->files->all('files'); if (!is_array($files)) $files=[];
        $folder=$this->optionalInt($request->request->get('folder_id')); $count=0;
        try { foreach($files as $file){ if(!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) continue; $mime=strtolower((string)$file->getMimeType()); $saved=str_starts_with($mime,'video/')?$this->videos->upload($file):$this->images->upload($file,$ctx->storeId); $this->metadata->save($ctx->storeId,$saved->assetId,new MediaMetadata($folder)); $count++; } $this->addFlash('success',$count.\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.medialibraryadmincontroller.fail_iv_zavantazheno_zobrazhennia_optymizovano_video')); }
        catch(\Throwable $e){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.medialibraryadmincontroller.zavantazhennia_zupyneno').\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));}
        return $this->redirectToRoute('admin_media_library',$folder?['folder'=>$folder]:[]);
    }


    /** Same as the form upload, but answers with JSON so pickers can upload without leaving the page. */
    #[Route('/admin/media/upload.json', name:'admin_media_upload_json', methods:['POST'])]
    public function uploadJson(Request $request): JsonResponse
    {
        $ctx=$this->contexts->resolve($request);
        if(!$this->isCsrfTokenValid('media_upload',(string)$request->request->get('_csrf_token'))){return $this->json(['ok'=>false],403);}
        $files=$request->files->all('files'); if(!is_array($files)){$files=[];}
        $folder=$this->optionalInt($request->request->get('folder_id')); $ids=[]; $failed=0;
        foreach($files as $file){
            if(!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile){continue;}
            try{$mime=strtolower((string)$file->getMimeType()); $saved=str_starts_with($mime,'video/')?$this->videos->upload($file):$this->images->upload($file,$ctx->storeId); $this->metadata->save($ctx->storeId,$saved->assetId,new MediaMetadata($folder)); $ids[]=$saved->assetId;}
            catch(\Throwable){++$failed;}
        }
        return $this->json(['ok'=>$failed===0,'uploaded'=>$ids,'failed'=>$failed],$ids===[]&&$failed>0?422:200);
    }

    #[Route('/admin/media/bulk/move', name:'admin_media_bulk_move', methods:['POST'])]
    public function bulkMove(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request); $this->csrf($request,'media_bulk');
        try{$n=$this->library->moveMany($ctx->storeId,array_map('intval',(array)$request->request->all('ids')),$this->optionalInt($request->request->get('folder_id'))); $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('admin.media.bulk.moved',['count'=>$n]));}
        catch(\Throwable){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));}
        return $this->redirectToRoute('admin_media_library',$this->backQuery($request));
    }

    #[Route('/admin/media/bulk/delete', name:'admin_media_bulk_delete', methods:['POST'])]
    public function bulkDelete(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request); $this->csrf($request,'media_bulk');
        try{[$done,$busy]=$this->library->deleteMany($ctx->storeId,array_map('intval',(array)$request->request->all('ids'))); $this->addFlash($busy>0?'warning':'success',\Commerce\Core\I18n\CanonicalUiText::get('admin.media.bulk.deleted',['count'=>$done,'busy'=>$busy]));}
        catch(\Throwable){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));}
        return $this->redirectToRoute('admin_media_library',$this->backQuery($request));
    }

    #[Route('/admin/media/folder/{folderId}/delete', name:'admin_media_folder_delete', methods:['POST'], requirements:['folderId'=>'\\d+'])]
    public function deleteFolder(Request $request,int $folderId): Response
    {
        $ctx=$this->contexts->resolve($request); $this->csrf($request,'media_folder_delete_'.$folderId);
        try{$this->library->deleteFolder($ctx->storeId,$folderId);$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('admin.media.folder.deleted'));}catch(\Throwable){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));}
        return $this->redirectToRoute('admin_media_library');
    }

    #[Route('/admin/media/settings', name:'admin_media_settings', methods:['POST'])]
    public function settings(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request); $this->csrf($request,'media_settings');
        $user=$this->getUser(); $actor=$user instanceof AdminUser?'admin:'.$user->id:'admin';
        $r=$request->request;
        $profile=MediaImageProfile::fromPreset((string)$r->get('preset','recommended'),[
            'format'=>(string)$r->get('format','webp'),
            'widths'=>array_map('intval',(array)$r->all('widths')),
            'include_original'=>$r->has('include_original'),
            'quality'=>(int)$r->get('quality',82),
            'keep_source'=>$r->has('keep_source'),
            'jpeg_fallback'=>$r->has('jpeg_fallback'),
        ]);
        try{
            $this->revisions->activateStoreJson($ctx->storeId,'media','image_processing',$profile,$actor);
            $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('admin.media.library.processing_saved'));
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
        $ctx=$this->contexts->resolve($request); $this->csrf($request,'media_metadata_'.$assetId);
        $tags=array_values(array_filter(array_map('trim',explode(',',(string)$request->request->get('tags','')))));
        try{$this->metadata->save($ctx->storeId,$assetId,new MediaMetadata($this->optionalInt($request->request->get('folder_id')),(string)$request->request->get('alt_text',''),(string)$request->request->get('title',''),(float)$request->request->get('focal_x',50),(float)$request->request->get('focal_y',50),$tags));$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.medialibraryadmincontroller.metadani_zberezheno'));}catch(\Throwable $e){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));}
        return $this->redirectToRoute('admin_media_library');
    }

    #[Route('/admin/media/{assetId}/delete', name:'admin_media_delete', methods:['POST'], requirements:['assetId'=>'\\d+'])]
    public function delete(Request $request,int $assetId): Response
    {
        $ctx=$this->contexts->resolve($request); $this->csrf($request,'media_delete_'.$assetId);
        try{$this->library->delete($ctx->storeId,$assetId);$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.medialibraryadmincontroller.fail_vydaleno_z_biblioteky'));}catch(\Throwable $e){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));}
        return $this->redirectToRoute('admin_media_library');
    }


    /** @return array{format:string,widths:list<int>,include_original:bool,quality:int,keep_source:bool,jpeg_fallback:bool,preset:string} */
    private function processing(int $storeId): array
    {
        $input=$this->revisions->latestValidPayload($storeId,'media','image_processing');
        $profile=MediaImageProfile::normalize(is_array($input)?$input:[]);

        return $profile+['preset'=>MediaImageProfile::detect($profile)];
    }

    /** @return array<string,string> */
    private function backQuery(Request $request): array { $f=(string)$request->request->get('folder_back',''); return $f!==''&&(ctype_digit($f)||$f==='none')?['folder'=>$f]:[]; }

    /** folder query: empty = all, "none" = unfiled (0), id = folder */
    private function folderFilter(mixed $value): ?int { if($value==='none'){return 0;} return $this->optionalInt($value); }
    private function optionalInt(mixed $value): ?int { $v=(int)$value; return $v>0?$v:null; }
    private function csrf(Request $request,string $id): void { if(!$this->isCsrfTokenValid($id,(string)$request->request->get('_csrf_token'))) throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf')); }
}
