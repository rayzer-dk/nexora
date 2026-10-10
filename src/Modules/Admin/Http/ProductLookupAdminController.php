<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** Product search for the pickers of the product form ("Related", "Bought together"): by name or article, or suggestions from the same category. */
final class ProductLookupAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly Connection $db)
    {
    }

    #[Route('/admin/catalog/products/lookup', name: 'admin_catalog_product_lookup', methods: ['GET'], priority: 10)]
    public function lookup(Request $request): JsonResponse
    {
        $context = $this->contexts->resolve($request);
        $query = trim(mb_substr((string) $request->query->get('q', ''), 0, 80, 'UTF-8'));
        $params = [$context->storeId, $context->locale, $context->storeId];
        $where = '';
        $order = 'pt.name';
        $suggestFor = trim((string) $request->query->get('for', ''));
        if ($query !== '') {
            $like = '%' . addcslashes($query, '%_\\') . '%';
            $where = ' AND (pt.name LIKE ? OR v.sku LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        } elseif ($suggestFor !== '') {
            try {
                $binary = Uuid::fromString($suggestFor)->toBinary();
            } catch (\Throwable) {
                return new JsonResponse(['items' => []]);
            }
            $where = ' AND p.public_id<>? AND EXISTS (SELECT 1 FROM mc_product_category pc JOIN mc_product_category pc2 ON pc2.category_id=pc.category_id AND pc2.product_id=p.id JOIN mc_product me ON me.id=pc.product_id AND me.public_id=?)';
            $params[] = $binary;
            $params[] = $binary;
            $order = 'p.id DESC';
        } else {
            return new JsonResponse(['items' => []]);
        }
        $rows = $this->db->fetchAllAssociative(
            "SELECT v.sku,pt.name FROM mc_product p
             JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=?
             JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=sp.store_id AND pt.locale=?
             JOIN mc_product_variant v ON v.product_id=p.id AND v.sort_order=0
             WHERE sp.store_id=?{$where} ORDER BY {$order} LIMIT 12",
            $params,
        );

        return new JsonResponse(['items' => array_map(static fn (array $r): array => ['sku' => (string) $r['sku'], 'name' => (string) $r['name']], $rows)]);
    }
}
