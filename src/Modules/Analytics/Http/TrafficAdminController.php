<?php

declare(strict_types=1);

namespace Commerce\Modules\Analytics\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Analytics\Visit\VisitReport;
use Commerce\Modules\Analytics\Visit\VisitTracker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TrafficAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly VisitReport $report, private readonly VisitTracker $tracker)
    {
    }

    #[Route('/admin/analytics/traffic', name: 'admin_analytics_traffic', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $storeId = $this->contexts->resolve($request)->storeId;

        return $this->render('@storefront/admin/analytics/traffic.html.twig', [
            'report' => $this->report->build($storeId, $request->query->getInt('days', 30)),
            'settings' => $this->tracker->settings($storeId),
        ]);
    }

    #[Route('/admin/analytics/traffic/settings', name: 'admin_analytics_traffic_settings', methods: ['POST'])]
    public function settings(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_traffic', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $this->tracker->saveSettings($this->contexts->resolve($request)->storeId, $request->request->has('enabled'), $request->request->getInt('retention_days', 400));
        $this->addFlash('success', CanonicalUiText::get('admin.traffic.saved'));

        return $this->redirectToRoute('admin_analytics_traffic');
    }
}
