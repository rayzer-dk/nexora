<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Catalog\Application\ImageAltService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** ALT text templates for product photos, a preview of what would be written, and a one-click fill of the empty fields. */
final class ImageAltAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly ImageAltService $alt)
    {
    }

    #[Route('/admin/catalog/image-alt', name: 'admin_catalog_image_alt', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $overwrite = $request->query->getBoolean('overwrite');

        return $this->render('@storefront/admin/catalog/image_alt.html.twig', [
            's' => $this->alt->settings(),
            'variables' => ImageAltService::VARIABLES,
            'overwrite' => $overwrite,
            'plan' => $this->alt->plan($context->storeId, $context->locale, $overwrite, 25),
            'total' => count($this->alt->plan($context->storeId, $context->locale, $overwrite, 100000)),
        ]);
    }

    #[Route('/admin/catalog/image-alt/save', name: 'admin_catalog_image_alt_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_image_alt', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $this->alt->save($request->request->all());
        $this->addFlash('success', CanonicalUiText::get('admin.image_alt.saved'));

        return $this->redirectToRoute('admin_catalog_image_alt');
    }

    #[Route('/admin/catalog/image-alt/apply', name: 'admin_catalog_image_alt_apply', methods: ['POST'])]
    public function apply(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_image_alt', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $count = $this->alt->apply($context->storeId, $context->locale, $request->request->getBoolean('overwrite'));
        $this->addFlash('success', CanonicalUiText::get('admin.image_alt.applied', ['count' => (string) $count]));

        return $this->redirectToRoute('admin_catalog_image_alt');
    }
}
