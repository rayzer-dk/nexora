<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Appearance\Infrastructure\ContactWidgetSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ContactWidgetAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly ContactWidgetSettings $settings)
    {
    }

    #[Route('/admin/appearance/contact-widget', name: 'admin_appearance_contact_widget', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_contact_widget', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
            }
            try {
                $this->settings->save($context->storeId, $request->request->all());
                $this->addFlash('success', CanonicalUiText::get('admin.cw.saved'));
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('admin_appearance_contact_widget');
        }

        return $this->render('@storefront/admin/appearance/contact_widget.html.twig', ['s' => $this->settings->get($context->storeId)]);
    }
}
