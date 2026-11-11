<?php

declare(strict_types=1);

namespace Commerce\Modules\Analytics\Http;

use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Analytics\Vitals\WebVitals;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VitalsAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly WebVitals $vitals)
    {
    }

    #[Route('/admin/analytics/vitals', name: 'admin_analytics_vitals', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $days = in_array($request->query->getInt('days', 7), [7, 30, 90], true) ? $request->query->getInt('days', 7) : 7;

        return $this->render('@storefront/admin/analytics/vitals.html.twig', [
            'days' => $days,
            'rows' => $this->vitals->report($this->contexts->resolve($request)->storeId, $days),
            'limits' => WebVitals::LIMITS,
        ]);
    }
}
