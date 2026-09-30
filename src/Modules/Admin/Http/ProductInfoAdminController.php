<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\ProductInfo\Application\ProductInfoService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** Saves the purchase-information blocks (rich text, lists, size tables) edited on the product page. */
final class ProductInfoAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly ProductInfoService $info, private readonly Connection $db)
    {
    }

    #[Route('/admin/catalog/products/{publicId}/info-blocks', name: 'admin_catalog_product_info_save', methods: ['POST'])]
    public function save(string $publicId, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_product_info_' . $publicId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $storeId = $this->contexts->resolve($request)->storeId;
        try {
            $binary = Uuid::fromString($publicId)->toBinary();
        } catch (\Throwable) {
            throw $this->createNotFoundException();
        }
        $productId = $this->db->fetchOne('SELECT p.id FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? WHERE p.public_id=? LIMIT 1', [$storeId, $binary]);
        if ($productId === false) {
            throw $this->createNotFoundException();
        }
        $this->info->save((int) $productId, $request->request->all('block'));
        $this->addFlash('success', CanonicalUiText::get('admin.infoblocks.saved'));

        return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
    }
}
