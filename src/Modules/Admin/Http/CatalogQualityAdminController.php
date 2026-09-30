<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Catalog\Application\CatalogQualityService;
use Commerce\Modules\Localization\Application\ContentPolicyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Catalogue completeness (texts, pictures, price, stock, languages) and the publishing policy for missing translations. */
final class CatalogQualityAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly CatalogQualityService $quality, private readonly ContentPolicyService $policy)
    {
    }

    #[Route('/admin/catalog/quality', name: 'admin_catalog_quality', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $storeId = $this->contexts->resolve($request)->storeId;
        $issue = (string) $request->query->get('issue', '');

        return $this->render('@storefront/admin/catalog/quality.html.twig', [
            'summary' => $this->quality->summary($storeId),
            'list' => $this->quality->products($storeId, $issue, $request->query->getInt('page', 1)),
            'issue' => $issue,
            'core' => CatalogQualityService::CORE,
            'extra' => CatalogQualityService::EXTRA,
            'policy' => $this->policy->mode($storeId),
            'modes' => ContentPolicyService::MODES,
        ]);
    }

    #[Route('/admin/catalog/quality/policy', name: 'admin_catalog_quality_policy', methods: ['POST'])]
    public function savePolicy(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_catalog_quality', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        try {
            $this->policy->save($this->contexts->resolve($request)->storeId, (string) $request->request->get('mode', ''));
            $this->addFlash('success', CanonicalUiText::get('admin.catalog_quality.policy_saved'));
        } catch (\InvalidArgumentException) {
            $this->addFlash('error', CanonicalUiText::get('admin.catalog_quality.policy_invalid'));
        }

        return $this->redirectToRoute('admin_catalog_quality');
    }
}
