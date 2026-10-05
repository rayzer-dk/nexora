<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Inventory\Application\StockNotificationService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** Shoppers who asked to be told when a product is back: who waits for what, since when, with search, period and sorting. */
final class StockRequestAdminController extends AbstractController
{
    private const PER_PAGE = 50;
    private const SORTS = ['created' => 'r.created_at', 'product' => 'product_name', 'email' => 'r.email', 'status' => 'r.status', 'stock' => 'available'];

    public function __construct(private readonly AdminContextResolver $contexts, private readonly Connection $db, private readonly StockNotificationService $notifications)
    {
    }

    #[Route('/admin/commerce/stock-requests', name: 'admin_commerce_stock_requests', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        [$where, $params, $filters] = $this->filters($request, $context->storeId);
        $sort = (string) $request->query->get('sort', 'created');
        $sort = isset(self::SORTS[$sort]) ? $sort : 'created';
        $dir = strtolower((string) $request->query->get('dir', 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $page = max(1, (int) $request->query->get('page', 1));
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_stock_notification_request r JOIN mc_product p ON p.id=r.product_id JOIN mc_product_variant v ON v.id=r.variant_id LEFT JOIN mc_product_translation pt ON pt.product_id=r.product_id AND pt.store_id=r.store_id AND pt.locale=? WHERE ' . $where, [$context->locale, ...$params]);
        $rows = $this->db->fetchAllAssociative(
            $this->select() . ' WHERE ' . $where . ' ORDER BY ' . self::SORTS[$sort] . ' ' . $dir . ', r.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            [$context->locale, ...$params],
        );
        foreach ($rows as &$row) {
            $row['product_uuid'] = Uuid::fromBinary((string) $row['product_public_id'])->toRfc4122();
            $row['request_uuid'] = Uuid::fromBinary((string) $row['public_id'])->toRfc4122();
        }
        unset($row);
        $top = $this->db->fetchAllAssociative("SELECT COALESCE(pt.name,v.sku) product_name,v.sku,SUM(r.status='active') waiting,SUM(r.status='pending') unconfirmed FROM mc_stock_notification_request r JOIN mc_product_variant v ON v.id=r.variant_id LEFT JOIN mc_product_translation pt ON pt.product_id=r.product_id AND pt.store_id=r.store_id AND pt.locale=? WHERE r.store_id=? AND r.status IN ('active','pending') GROUP BY r.variant_id,pt.name,v.sku ORDER BY waiting DESC,unconfirmed DESC LIMIT 5", [$context->locale, $context->storeId]);
        $stats = $this->db->fetchAssociative("SELECT SUM(status='active') waiting,SUM(status='pending') unconfirmed,SUM(status='notified') notified,SUM(admin_seen_at IS NULL AND status='active') unseen FROM mc_stock_notification_request WHERE store_id=?", [$context->storeId]) ?: [];
        // Opening the list is "seeing" the new requests: the header bell goes quiet.
        $this->db->executeStatement("UPDATE mc_stock_notification_request SET admin_seen_at=UTC_TIMESTAMP(6) WHERE store_id=? AND admin_seen_at IS NULL AND status='active'", [$context->storeId]);

        return $this->render('@storefront/admin/commerce/stock_requests.html.twig', [
            'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'filters' => $filters, 'sort' => $sort, 'dir' => strtolower($dir), 'top' => $top, 'stats' => $stats,
        ]);
    }

    #[Route('/admin/commerce/stock-requests/export.csv', name: 'admin_commerce_stock_requests_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        [$where, $params] = $this->filters($request, $context->storeId);
        $rows = $this->db->fetchAllAssociative($this->select() . ' WHERE ' . $where . ' ORDER BY r.created_at DESC LIMIT 20000', [$context->locale, ...$params]);
        $response = new StreamedResponse(static function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['email', 'product', 'sku', 'status', 'created_at', 'confirmed_at', 'notified_at', 'locale', 'in_stock']);
            foreach ($rows as $row) {
                // Cells that start like a formula are neutralised so a spreadsheet never runs them.
                $cells = [$row['email'], $row['product_name'], $row['sku'], $row['status'], $row['created_at'], $row['confirmed_at'], $row['notified_at'], $row['locale'], (float) $row['available'] > 0 ? 'yes' : 'no'];
                fputcsv($out, array_map(static fn (mixed $c): string => preg_match('/^[=+\-@\t\r]/', (string) $c) === 1 ? "'" . $c : (string) $c, $cells));
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="stock-requests-' . gmdate('Ymd') . '.csv"');

        return $response;
    }

    #[Route('/admin/commerce/stock-requests/{id}/delete', name: 'admin_commerce_stock_requests_delete', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function delete(string $id, Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_stock_requests', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $this->db->executeStatement('DELETE FROM mc_stock_notification_request WHERE public_id=? AND store_id=?', [Uuid::fromString($id)->toBinary(), $context->storeId]);
        $this->addFlash('success', CanonicalUiText::get('admin.stockrequests.deleted'));

        return $this->redirectToRoute('admin_commerce_stock_requests');
    }

    #[Route('/admin/commerce/stock-requests/notify/{product}', name: 'admin_commerce_stock_requests_notify', methods: ['POST'], requirements: ['product' => '[0-9a-f-]{36}'])]
    public function notify(string $product, Request $request): Response
    {
        $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_stock_requests', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $this->notifications->notifyAvailableProduct($product);
        $this->addFlash('success', CanonicalUiText::get('admin.stockrequests.notified_ok'));

        return $this->redirectToRoute('admin_commerce_stock_requests');
    }

    private function select(): string
    {
        return "SELECT r.id,r.public_id,r.email,r.locale,r.status,r.created_at,r.confirmed_at,r.notified_at,r.admin_seen_at,p.public_id product_public_id,COALESCE(pt.name,v.sku) product_name,v.sku,
                COALESCE((SELECT SUM(GREATEST(sl.stocked_quantity-sl.reserved_quantity-sl.safety_stock,0)) FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id WHERE vii.variant_id=v.id),0) available
                FROM mc_stock_notification_request r JOIN mc_product p ON p.id=r.product_id JOIN mc_product_variant v ON v.id=r.variant_id
                LEFT JOIN mc_product_translation pt ON pt.product_id=r.product_id AND pt.store_id=r.store_id AND pt.locale=?";
    }

    /** @return array{0:string,1:list<mixed>,2:array<string,string>} */
    private function filters(Request $request, int $storeId): array
    {
        $where = ['r.store_id=?'];
        $params = [$storeId];
        $q = trim(mb_substr((string) $request->query->get('q', ''), 0, 120));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $where[] = '(r.email LIKE ? OR pt.name LIKE ? OR v.sku LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $status = (string) $request->query->get('status', '');
        if (in_array($status, ['pending', 'active', 'notified'], true)) {
            $where[] = 'r.status=?';
            $params[] = $status;
        } else {
            $status = '';
        }
        $from = (string) $request->query->get('from', '');
        $to = (string) $request->query->get('to', '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) === 1) {
            $where[] = 'r.created_at>=?';
            $params[] = $from . ' 00:00:00';
        } else {
            $from = '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to) === 1) {
            $where[] = 'r.created_at<=?';
            $params[] = $to . ' 23:59:59.999999';
        } else {
            $to = '';
        }

        return [implode(' AND ', $where), $params, ['q' => $q, 'status' => $status, 'from' => $from, 'to' => $to]];
    }
}
