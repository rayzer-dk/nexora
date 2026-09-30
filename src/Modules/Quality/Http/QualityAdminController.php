<?php

declare(strict_types=1);

namespace Commerce\Modules\Quality\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Quality\Application\QualityFixer;
use Commerce\Modules\Quality\Application\QualityMonitor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Site quality monitor: one score, its history and a fix list across security, reliability, performance, content, languages and commerce. */
final class QualityAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly QualityMonitor $monitor, private readonly QualityFixer $fixer)
    {
    }

    #[Route('/admin/system/quality', name: 'admin_system_quality', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $storeId = $this->contexts->resolve($request)->storeId;
        $report = $this->monitor->run($storeId);
        $this->monitor->record($storeId, $report, 'view');

        return $this->render('@storefront/admin/system/quality.html.twig', [
            'report' => $report,
            'groups' => QualityMonitor::GROUPS,
            'history' => $this->monitor->history($storeId),
            'fixable' => QualityFixer::FIXABLE,
        ]);
    }

    #[Route('/admin/system/quality/fix/{id}', name: 'admin_system_quality_fix', methods: ['POST'], requirements: ['id' => '[a-z_]{3,40}'])]
    public function fix(Request $request, string $id): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_quality', (string) $request->request->get('_token')) || !in_array($id, QualityFixer::FIXABLE, true)) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        try {
            $changed = $this->fixer->fix($id, $this->contexts->resolve($request)->storeId);
            $this->addFlash('success', CanonicalUiText::get('admin.quality.fixed', ['check' => CanonicalUiText::get('admin.quality.check.' . $id), 'count' => (string) $changed]));
        } catch (\Throwable) {
            $this->addFlash('error', CanonicalUiText::get('admin.quality.fix_failed'));
        }

        return $this->redirectToRoute('admin_system_quality');
    }
}
