<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Admin\Authorization\AdminAuthorizationService;
use Commerce\Modules\Admin\Authorization\AdminPermissionCatalog;
use Commerce\Modules\Admin\Domain\AdminUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Data search behind Ctrl+K: products, orders and customers by what the merchant types. Each kind is
 * returned only when the signed-in admin may view it, so the palette never leaks more than the lists do.
 */
final class QuickSearchAdminController extends AbstractController
{
    private const LIMIT = 5;

    public function __construct(private readonly AdminContextResolver $contexts, private readonly Connection $db, private readonly AdminAuthorizationService $authorization)
    {
    }

    #[Route('/admin/api/quick-search', name: 'admin_api_quick_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $query = trim((string) $request->query->get('q', ''));
        if (!$user instanceof AdminUser || mb_strlen($query) < 2 || mb_strlen($query) > 60) {
            return new JsonResponse(['results' => []]);
        }
        $storeId = $this->contexts->resolve($request)->storeId;
        $like = '%' . addcslashes($query, '\\%_') . '%';
        $results = [];
        try {
            if ($this->authorization->isGranted($user, AdminPermissionCatalog::CATALOG_VIEW, $storeId)) {
                $rows = $this->db->fetchAllAssociative(
                    "SELECT DISTINCT p.public_id,t.name,(SELECT v.sku FROM mc_product_variant v WHERE v.product_id=p.id ORDER BY v.sort_order LIMIT 1) sku
                     FROM mc_product p JOIN mc_product_translation t ON t.product_id=p.id AND t.store_id=?
                     WHERE t.name LIKE ? OR EXISTS (SELECT 1 FROM mc_product_variant v WHERE v.product_id=p.id AND v.sku LIKE ?)
                     ORDER BY p.id DESC LIMIT " . self::LIMIT,
                    [$storeId, $like, $like],
                );
                foreach ($rows as $row) {
                    $results[] = ['type' => 'product', 'label' => (string) $row['name'], 'detail' => (string) ($row['sku'] ?? ''), 'href' => '/admin/catalog/products/' . Uuid::fromBinary((string) $row['public_id'])->toRfc4122() . '/edit'];
                }
            }
            if ($this->authorization->isGranted($user, AdminPermissionCatalog::ORDERS_VIEW, $storeId)) {
                $rows = $this->db->fetchAllAssociative(
                    'SELECT public_id,order_number,customer_name,customer_email FROM mc_sales_order WHERE store_id=? AND (order_number LIKE ? OR customer_email LIKE ? OR customer_name LIKE ? OR customer_phone LIKE ?) ORDER BY id DESC LIMIT ' . self::LIMIT,
                    [$storeId, $like, $like, $like, $like],
                );
                foreach ($rows as $row) {
                    $results[] = ['type' => 'order', 'label' => (string) $row['order_number'], 'detail' => trim((string) $row['customer_name'] . ' ' . (string) $row['customer_email']), 'href' => '/admin/orders/' . Uuid::fromBinary((string) $row['public_id'])->toRfc4122()];
                }
            }
            if ($this->authorization->isGranted($user, AdminPermissionCatalog::CUSTOMERS_VIEW, $storeId)) {
                $rows = $this->db->fetchAllAssociative(
                    "SELECT display_name,email FROM mc_customer WHERE status<>'deleted' AND (email LIKE ? OR display_name LIKE ? OR phone_e164 LIKE ?) ORDER BY id DESC LIMIT " . self::LIMIT,
                    [$like, $like, $like],
                );
                foreach ($rows as $row) {
                    $results[] = ['type' => 'customer', 'label' => (string) ($row['display_name'] ?: $row['email']), 'detail' => (string) $row['email'], 'href' => '/admin/commerce/customers?search=' . rawurlencode((string) $row['email'])];
                }
            }
        } catch (\Throwable) {
            // a search box must never throw at the merchant
        }

        return new JsonResponse(['results' => $results]);
    }
}
