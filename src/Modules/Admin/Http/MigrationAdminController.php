<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Migration\Application\MigrationCatalogImporter;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MigrationAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly Connection $db,
        private readonly MigrationCatalogImporter $importer,
        private readonly \Commerce\Modules\Migration\Application\MigrationDryRunAnalyzer $dryRun,
        private readonly \Commerce\Modules\Migration\Application\MigrationTargetConflictAnalyzer $targetConflicts,
    ) {}

    #[Route('/admin/system/migration', name:'admin_system_migration', methods:['GET'])]
    public function index(Request $request): Response
    {
        $this->contexts->resolve($request);
        $runs=$this->db->fetchAllAssociative(
            "SELECT r.public_id,r.source_code,r.status,r.entity_type,r.cursor_value,r.processed_count,r.issue_count,r.last_error,r.created_at,r.updated_at,r.completed_at,
                    j.id AS job_id,j.statistics,
                    (SELECT COUNT(*) FROM mc_import_item i WHERE i.job_id=j.id) AS item_count,
                    (SELECT COUNT(*) FROM mc_import_issue x WHERE x.job_id=j.id AND x.severity='error') AS error_count,
                    (SELECT COUNT(*) FROM mc_import_issue x WHERE x.job_id=j.id AND x.severity='warning') AS warning_count
             FROM mc_migration_run r
             LEFT JOIN mc_import_job j ON JSON_UNQUOTE(JSON_EXTRACT(j.options,'$.run_id'))=r.public_id
             ORDER BY r.id DESC LIMIT 100"
        );
        return $this->render('@storefront/admin/system/migration.html.twig',['runs'=>$runs,'oc_report'=>null,'oc_form'=>['host'=>'localhost','port'=>'3306','database'=>'','user'=>'','prefix'=>'oc_','image_root'=>'','publish'=>false]]);
    }

    /**
     * Import from an OpenCart / ocStore 3 shop without a command line (shared hosting has none): the old shop's database access is typed
     * here, used for this request only and never stored. "Check" only reads; "Import" writes in the current store.
     */
    #[Route('/admin/system/migration/opencart', name:'admin_system_migration_opencart', methods:['POST'])]
    public function opencart(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request);
        if(!$this->isCsrfTokenValid('migration_opencart',(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
        $r=$request->request;
        $form=['host'=>trim((string)$r->get('host','')),'port'=>trim((string)$r->get('port','3306')),'database'=>trim((string)$r->get('database','')),'user'=>trim((string)$r->get('db_user','')),'prefix'=>trim((string)$r->get('prefix','oc_')),'image_root'=>trim((string)$r->get('image_root','')),'publish'=>$r->getBoolean('publish')];
        $report=null;
        try{
            if(preg_match('/^[A-Za-z0-9._-]{1,190}$/D',$form['host'])!==1||preg_match('/^\d{2,5}$/D',$form['port'])!==1||preg_match('/^[A-Za-z0-9_$-]{1,64}$/D',$form['database'])!==1||$form['user']===''||preg_match('/^[A-Za-z0-9_]{0,32}$/D',$form['prefix'])!==1){
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.migration.oc.invalid'));
            }
            $imageRoot=null;
            if($form['image_root']!==''){$real=realpath($form['image_root']);if($real===false||!is_dir($real)||!is_readable($real))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.migration.oc.image_root_error'));$imageRoot=$real;}
            @set_time_limit(0);ignore_user_abort(true);
            $pdo=new \PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$form['host'],(int)$form['port'],$form['database']),$form['user'],(string)$r->get('db_pass',''),[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_EMULATE_PREPARES=>false,\PDO::ATTR_TIMEOUT=>10]);
            $source=new \Commerce\Modules\Migration\Source\OpenCart\OpenCart3CatalogSource($pdo,$form['prefix'],new \Commerce\Modules\Localization\Domain\LocaleNormalizer());
            $analysis=$this->dryRun->analyze($source,250);
            $errors=0;$issues=[];foreach($analysis->issues as $issue){if($issue->severity==='error')++$errors;if(count($issues)<60)$issues[]=['severity'=>$issue->severity,'code'=>$issue->code,'key'=>$issue->sourceKey,'message'=>$issue->message];}
            $report=['counts'=>$analysis->counts,'errors'=>$errors,'issues'=>$issues,'applied'=>null];
            if($r->get('mode')==='apply'){
                $plan=new \Commerce\Modules\Migration\Application\MigrationImportPlan($ctx->storeId,$ctx->marketId,$ctx->locale,strtoupper($ctx->currency),'oc-'.substr(sha1($form['host'].'|'.$form['database'].'|'.$form['prefix']),0,16),[],$form['publish'],true,250,$imageRoot);
                $result=$this->importer->import($source,$plan,null);
                $report['applied']=['run'=>$result->runId,'created'=>$result->counts['created']??0,'reused'=>$result->counts['reused']??0,'skipped'=>$result->counts['skipped']??0,'failed'=>$result->counts['failed']??0,'issues'=>$result->issues,'problems'=>$this->runProblems($result->runId)];
            }
        }catch(\DomainException|\InvalidArgumentException $e){$this->addFlash('error',$e->getMessage());}
        catch(\PDOException){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('admin.migration.oc.connect'));}
        catch(\Throwable){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));}
        $runs=$this->db->fetchAllAssociative("SELECT r.public_id,r.source_code,r.status,r.entity_type,r.cursor_value,r.processed_count,r.issue_count,r.last_error,r.created_at,r.updated_at,r.completed_at,0 AS job_id,NULL AS statistics,0 AS item_count,0 AS error_count,0 AS warning_count FROM mc_migration_run r ORDER BY r.id DESC LIMIT 100");
        return $this->render('@storefront/admin/system/migration.html.twig',['runs'=>$runs,'oc_report'=>$report,'oc_form'=>$form]);
    }

    /** Every record the import could not take over, with the reason - the import itself carries on past them. @return list<array{type:string,key:string,code:string,message:string}> */
    private function runProblems(string $runId): array
    {
        try{
            $rows=$this->db->fetchAllAssociative("SELECT i.entity_type,i.source_key,i.code,i.message FROM mc_import_issue i JOIN mc_import_job j ON j.id=i.job_id WHERE JSON_UNQUOTE(JSON_EXTRACT(j.options,'$.run_id'))=? ORDER BY i.id LIMIT 1000",[$runId]);
        }catch(\Throwable){return [];}
        return array_map(static fn(array $r):array=>['type'=>(string)$r['entity_type'],'key'=>(string)$r['source_key'],'code'=>(string)$r['code'],'message'=>(string)$r['message']],$rows);
    }

    #[Route('/admin/system/migration/{runId}/rollback', name:'admin_system_migration_rollback', methods:['POST'], requirements:['runId'=>'[0-9a-fA-F-]{36}'])]
    public function rollback(Request $request,string $runId): Response
    {
        $this->contexts->resolve($request);
        if(!$this->isCsrfTokenValid('migration_rollback_'.$runId,(string)$request->request->get('_csrf_token'))){throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));}
        try{$result=$this->importer->rollback($runId);$this->addFlash('success',sprintf(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.migrationadmincontroller.vidkat_zaversheno_vydaleno_d_propushcheno_d'),$result['deleted'],$result['skipped']));}
        catch(\Throwable $e){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.appearanceadmincontroller.vidkat_ne_vykonano').\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));}
        return $this->redirectToRoute('admin_system_migration');
    }
}
