<?php

declare(strict_types=1);

namespace Commerce\Modules\Analytics\Http;

use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Analytics\Application\CommerceAnalyticsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AnalyticsAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly CommerceAnalyticsService $analytics, private readonly \Commerce\Modules\Analytics\Application\LostDemandService $lostDemand) {}

    #[Route('/admin/analytics', name:'admin_analytics', methods:['GET'])]
    public function index(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request);
        $days=$request->query->getInt('days',30);
        $from=trim((string)$request->query->get('from','')); $to=trim((string)$request->query->get('to',''));
        return $this->render('@storefront/admin/analytics/index.html.twig',['lost_demand'=>$this->lostDemand->report($ctx->storeId,$ctx->locale),'report'=>$this->analytics->report($ctx->storeId,$days,$from!==''?$from:null,$to!==''?$to:null)]);
    }

    #[Route('/admin/analytics/export.csv', name:'admin_analytics_export', methods:['GET'], priority:10)]
    public function export(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request);
        $from=trim((string)$request->query->get('from','')); $to=trim((string)$request->query->get('to',''));
        $report=$this->analytics->report($ctx->storeId,$request->query->getInt('days',30),$from!==''?$from:null,$to!==''?$to:null);
        $out=fopen('php://temp','r+'); fwrite($out,"\xEF\xBB\xBF"); fputcsv($out,['date','orders','revenue_'.$report['currency']],';');
        foreach($report['daily'] as $d){ fputcsv($out,[$d['day'],$d['orders'],number_format($d['revenue_minor']/100,2,'.','')],';'); }
        rewind($out); $csv=(string)stream_get_contents($out); fclose($out);
        return new Response($csv,200,['Content-Type'=>'text/csv; charset=utf-8','Content-Disposition'=>'attachment; filename="analytics-'.$report['from'].'-'.$report['to'].'.csv"']);
    }
}
