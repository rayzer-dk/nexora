<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Seo\Application\IndexNowService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class IndexNowAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly IndexNowService $indexNow)
    {
    }

    #[Route('/admin/system/seo-indexnow', name: 'admin_system_seo_indexnow', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);

        return $this->render('@storefront/admin/system/indexnow.html.twig', [
            'state' => $this->indexNow->state($context->storeId),
            'key_location' => $this->indexNow->keyLocation(),
            'usable' => $this->indexNow->usable(),
            'host' => $this->indexNow->host(),
        ]);
    }

    #[Route('/admin/system/seo-indexnow/save', name: 'admin_system_seo_indexnow_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_indexnow', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        if ($request->request->get('action') === 'submit_all') {
            $result = $this->indexNow->submit($context->storeId, true);
            $this->addFlash($result['ok'] ? 'success' : 'error', CanonicalUiText::get($result['ok'] ? 'admin.indexnow.sent' : 'admin.indexnow.failed') . ' ' . $result['count'] . ' · ' . $result['status']);
        } else {
            $this->indexNow->setEnabled($context->storeId, $request->request->get('enabled') === '1');
            $this->addFlash('success', CanonicalUiText::get('admin.indexnow.saved'));
        }

        return $this->redirectToRoute('admin_system_seo_indexnow');
    }
}
