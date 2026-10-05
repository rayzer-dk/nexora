<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Core\Site\SiteCapabilitySettings;
use Commerce\Modules\Seo\Application\LlmsSettings;
use Commerce\Modules\Seo\Application\LlmsTxtBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The shop's introduction for AI assistants (/llms.txt): what to say about the shop, how much of the catalogue to list, and a live preview. */
final class LlmsAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly LlmsSettings $settings,
        private readonly LlmsTxtBuilder $builder,
        private readonly SiteCapabilitySettings $capabilities,
        private readonly \Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver $storefront,
    ) {
    }

    #[Route('/admin/system/ai-ready', name: 'admin_system_ai_ready', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_ai_ready', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
            }
            $this->settings->save($request->request->all());
            $this->addFlash('success', CanonicalUiText::get('admin.ai_ready.saved'));

            return $this->redirectToRoute('admin_system_ai_ready');
        }
        $preview = '';
        try {
            $features = (array) ($this->capabilities->get($context->storeId)['features'] ?? []);
            $preview = $this->builder->build($this->storefront->resolve($request), true, $features);
        } catch (\Throwable) {
            $preview = '';
        }

        return $this->render('@storefront/admin/system/ai_ready.html.twig', ['s' => $this->settings->all(), 'preview' => mb_substr($preview, 0, 12000, 'UTF-8')]);
    }
}
