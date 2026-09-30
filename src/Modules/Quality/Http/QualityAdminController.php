<?php

declare(strict_types=1);

namespace Commerce\Modules\Quality\Http;

use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Quality\Application\QualityMonitor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Site quality monitor: one score and a fix list across security, reliability, performance, content, languages and commerce. */
final class QualityAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly QualityMonitor $monitor)
    {
    }

    #[Route('/admin/system/quality', name: 'admin_system_quality', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('@storefront/admin/system/quality.html.twig', [
            'report' => $this->monitor->run($this->contexts->resolve($request)->storeId),
            'groups' => QualityMonitor::GROUPS,
        ]);
    }
}
