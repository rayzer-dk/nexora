<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Demo\Application\DemoSeeder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin → System → Demo data: install, refresh or remove the showcase catalogue without a console. Only the records the demo
 * created are touched (the seeder marks them); the shop's own products, orders and settings stay as they are.
 */
final class DemoDataAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly DemoSeeder $seeder,
    ) {
    }

    #[Route('/admin/system/demo', name: 'admin_system_demo', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->contexts->resolve($request);

        return $this->render('@storefront/admin/system/demo.html.twig');
    }

    #[Route('/admin/system/demo/install', name: 'admin_system_demo_install', methods: ['POST'])]
    public function install(Request $request): RedirectResponse
    {
        $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_system_demo', (string) $request->request->get('_token'))) {
            $this->addFlash('error', CanonicalUiText::get('admin.demo.failed'));

            return $this->redirectToRoute('admin_system_demo');
        }
        @set_time_limit(300);
        try {
            $result = $this->seeder->install();
            $this->addFlash('success', CanonicalUiText::get('admin.demo.installed', ['categories' => $result['categories'], 'products' => $result['products'], 'articles' => $result['articles']]));
        } catch (\Throwable $e) {
            $this->addFlash('error', CanonicalUiText::get('admin.demo.failed') . ' ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_system_demo');
    }

    #[Route('/admin/system/demo/remove', name: 'admin_system_demo_remove', methods: ['POST'])]
    public function remove(Request $request): RedirectResponse
    {
        $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_system_demo', (string) $request->request->get('_token'))) {
            $this->addFlash('error', CanonicalUiText::get('admin.demo.failed'));

            return $this->redirectToRoute('admin_system_demo');
        }
        @set_time_limit(300);
        try {
            $this->seeder->remove();
            $this->addFlash('success', CanonicalUiText::get('admin.demo.removed'));
        } catch (\Throwable $e) {
            $this->addFlash('error', CanonicalUiText::get('admin.demo.failed') . ' ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_system_demo');
    }
}
