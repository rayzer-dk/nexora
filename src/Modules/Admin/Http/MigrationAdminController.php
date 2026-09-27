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
        return $this->render('@storefront/admin/system/migration.html.twig',['runs'=>$runs]);
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
