<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Analytics\Infrastructure\TrackingSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TrackingAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly TrackingSettings $settings)
    {
    }

    #[Route('/admin/system/tracking', name: 'admin_system_tracking', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_tracking', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
            }
            try {
                $this->settings->save($context->storeId, $request->request->all());
                $this->addFlash('success', CanonicalUiText::get('admin.tracking.saved'));
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('admin_system_tracking');
        }

        return $this->render('@storefront/admin/system/tracking.html.twig', ['s' => $this->settings->get($context->storeId)]);
    }
}
