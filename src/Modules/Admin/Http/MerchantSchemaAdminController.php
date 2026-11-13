<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Seo\Application\MerchantSchemaSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MerchantSchemaAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly MerchantSchemaSettings $schema,
    ) {
    }

    #[Route('/admin/system/merchant-schema', name: 'admin_system_merchant_schema', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('merchant_schema', (string) $request->request->get('_token'))) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('admin.merchant.validation.session'));
                return $this->redirectToRoute('admin_system_merchant_schema');
            }
            try {
                $settings = $request->request->all();
                $settings['enabled'] = $request->request->getBoolean('enabled');
                $settings['shipping_enabled'] = $request->request->getBoolean('shipping_enabled');
                $settings['shipping_on_offers'] = $request->request->getBoolean('shipping_on_offers');
                $settings['returns_enabled'] = $request->request->getBoolean('returns_enabled');
                $actor = $this->getUser();
                $this->schema->save($context->storeId, $settings, $actor !== null ? $actor->getUserIdentifier() : 'admin');
                $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('admin.merchant.success'));
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('admin.merchant.error') . $e->getMessage());
            }
            return $this->redirectToRoute('admin_system_merchant_schema');
        }

        return $this->render('@storefront/admin/system/merchant_schema.html.twig', [
            'settings' => $this->schema->get($context->storeId),
            'store_id' => $context->storeId,
        ]);
    }
}
