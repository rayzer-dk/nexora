<?php

declare(strict_types=1);

namespace Commerce\Modules\Quality\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Quality\Application\LinkCheckService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Admin → System → Quality → Broken links and pictures. */
final class LinkCheckAdminController extends AbstractController
{
    public function __construct(private readonly LinkCheckService $check)
    {
    }

    #[Route('/admin/system/link-check', name: 'admin_system_link_check', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('@storefront/admin/system/link_check.html.twig', [
            'issues' => $this->check->issues($request->query->getBoolean('ignored')),
            'last' => $this->check->last(),
            'show_ignored' => $request->query->getBoolean('ignored'),
        ]);
    }

    #[Route('/admin/system/link-check/run', name: 'admin_system_link_check_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        $this->guard($request);
        $r = $this->check->run();
        $this->addFlash('success', CanonicalUiText::get('admin.linkcheck.done', ['documents' => (string) $r['documents'], 'issues' => (string) $r['issues']]));

        return $this->redirectToRoute('admin_system_link_check');
    }

    #[Route('/admin/system/link-check/{id}/ignore', name: 'admin_system_link_check_ignore', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ignore(int $id, Request $request): Response
    {
        $this->guard($request);
        $this->check->setIgnored($id, $request->request->getBoolean('ignored', true));

        return $this->redirectToRoute('admin_system_link_check');
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('link_check', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
