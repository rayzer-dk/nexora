<?php

declare(strict_types=1);

namespace Commerce\Modules\System\Http;

use Commerce\Core\Extension\ExtensionPackageManager;
use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\System\Application\CacheMaintenance;
use Commerce\Modules\System\Application\ModuleCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MaintenanceAdminController extends AbstractController
{
    public function __construct(
        private readonly CacheMaintenance $maintenance,
        private readonly ModuleCatalog $modules,
        private readonly ExtensionPackageManager $extensions,
    ) {
    }

    #[Route('/admin/system/maintenance', name: 'admin_system_maintenance', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@storefront/admin/system/maintenance.html.twig', ['overview' => $this->maintenance->overview()]);
    }

    #[Route('/admin/system/maintenance/{action}', name: 'admin_system_maintenance_run', methods: ['POST'], requirements: ['action' => '[a-z]+'])]
    public function run(string $action, Request $request): RedirectResponse
    {
        if (!in_array($action, CacheMaintenance::ACTIONS, true) || !$this->isCsrfTokenValid('admin_maintenance', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', CanonicalUiText::get('admin.system.maintenance.refused'));

            return $this->redirectToRoute('admin_system_maintenance');
        }
        try {
            $done = $this->maintenance->run($action);
            $this->addFlash('success', CanonicalUiText::get('admin.system.maintenance.done.' . $action, ['files' => $done['files'], 'size' => number_format($done['bytes'] / 1048576, 1, '.', ' ')]));
        } catch (\Throwable) {
            $this->addFlash('error', CanonicalUiText::get('admin.system.maintenance.failed'));
        }

        return $this->redirectToRoute('admin_system_maintenance');
    }

    #[Route('/admin/system/modules', name: 'admin_system_modules', methods: ['GET'])]
    public function modules(): Response
    {
        try {
            $extensions = $this->extensions->list();
        } catch (\Throwable) {
            $extensions = [];
        }

        return $this->render('@storefront/admin/system/modules.html.twig', [
            'modules' => $this->modules->builtIn(),
            'languages' => $this->modules->languages(),
            'extensions' => $extensions,
        ]);
    }
}
