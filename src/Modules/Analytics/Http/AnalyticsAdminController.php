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
    public function __construct(private readonly AdminContextResolver $contexts, private readonly CommerceAnalyticsService $analytics) {}

    #[Route('/admin/analytics', name:'admin_analytics', methods:['GET'])]
    public function index(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request);
        $days=$request->query->getInt('days',30);
        return $this->render('@storefront/admin/analytics/index.html.twig',['report'=>$this->analytics->report($ctx->storeId,$days)]);
    }
}
