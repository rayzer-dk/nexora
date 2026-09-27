<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class IntegrationsAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly Connection $db) {}

    #[Route('/admin/system/integrations', name:'admin_system_integrations', methods:['GET'])]
    public function index(Request $request): Response
    {
        $this->contexts->resolve($request);
        $queue=$this->db->fetchAllAssociative("SELECT integration_code,status,COUNT(*) total,MAX(updated_at) updated_at FROM mc_integration_sync_queue GROUP BY integration_code,status ORDER BY integration_code,status");
        $google=$this->db->fetchAllAssociative("SELECT s.name store_name,g.offer_id,g.content_language,g.feed_label,g.sync_status,g.issues_json,g.last_synced_at,g.updated_at FROM mc_google_merchant_product_state g JOIN mc_store s ON s.id=g.store_id ORDER BY g.updated_at DESC LIMIT 100");
        $marketing=$this->db->fetchAllAssociative("SELECT provider,event_name,consent_scope,status,attempt_count,response_code,last_error,sent_at,updated_at FROM mc_marketing_delivery ORDER BY id DESC LIMIT 100");
        $dead=$this->db->fetchAllAssociative("SELECT id,integration_code,aggregate_type,aggregate_id,operation,attempts,last_error,updated_at FROM mc_integration_sync_queue WHERE status='dead' ORDER BY id DESC LIMIT 100");
        return $this->render('@storefront/admin/system/integrations.html.twig',['queue'=>$queue,'google'=>$google,'marketing'=>$marketing,'dead'=>$dead]);
    }

    #[Route('/admin/system/integrations/queue/{id}/retry', name:'admin_system_integrations_retry', methods:['POST'], requirements:['id'=>'\\d+'])]
    public function retry(Request $request,int $id): Response
    {
        $this->contexts->resolve($request);
        if(!$this->isCsrfTokenValid('integration_retry_'.$id,(string)$request->request->get('_csrf_token'))){throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));}
        $this->db->update('mc_integration_sync_queue',['status'=>'pending','attempts'=>0,'available_at'=>gmdate('Y-m-d H:i:s.u'),'last_error'=>null,'updated_at'=>gmdate('Y-m-d H:i:s.u'),'completed_at'=>null],['id'=>$id]);
        $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.integrationsadmincontroller.zavdannia_povernuto_v_cherhu'));
        return $this->redirectToRoute('admin_system_integrations');
    }
}
