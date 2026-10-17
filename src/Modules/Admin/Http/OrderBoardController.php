<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Order\Application\OrderManagementService;
use Commerce\Modules\Order\Application\OrderNotificationService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** Orders as a board (drag a card to the next stage) and waybill stickers for the selected orders. */
final class OrderBoardController extends AbstractController
{
    /** Board columns: the stage, and the fulfilment statuses an order in it can have. */
    public const COLUMNS = ['new' => ['unfulfilled', 'pending'], 'preparing' => ['preparing'], 'ready_for_pickup' => ['ready_for_pickup'], 'shipped' => ['shipped'], 'delivered' => ['delivered']];
    private const PER_COLUMN = 40;

    public function __construct(
        private readonly Connection $db,
        private readonly AdminContextResolver $contexts,
        private readonly OrderManagementService $orders,
        private readonly OrderNotificationService $notifications,
    ) {
    }

    #[Route('/admin/orders/board', name: 'admin_order_board', methods: ['GET'], priority: 30)]
    public function board(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $columns = [];
        foreach (self::COLUMNS as $stage => $statuses) {
            $marks = implode(',', array_fill(0, count($statuses), '?'));
            $extra = $stage === 'delivered' ? ' AND o.updated_at>=?' : '';
            $params = array_merge([$context->storeId], $statuses, $stage === 'delivered' ? [gmdate('Y-m-d H:i:s', time() - 14 * 86400)] : []);
            $rows = $this->db->fetchAllAssociative(
                "SELECT o.public_id,o.order_number,o.customer_name,o.total_minor,o.currency,o.payment_status,o.fulfillment_status,o.created_at,
                        (SELECT f.tracking_number FROM mc_fulfillment f WHERE f.order_id=o.id ORDER BY f.id DESC LIMIT 1) AS tracking,
                        (SELECT COUNT(*) FROM mc_sales_order_item i WHERE i.order_id=o.id) AS items
                 FROM mc_sales_order o WHERE o.store_id=? AND o.fulfillment_status IN ($marks) AND o.status NOT IN ('cancelled','refunded','pending_approval')$extra
                 ORDER BY o.created_at DESC LIMIT " . self::PER_COLUMN,
                $params,
            );
            foreach ($rows as &$r) {
                $r['public_id'] = Uuid::fromBinary((string) $r['public_id'])->toRfc4122();
            }
            unset($r);
            $columns[$stage] = $rows;
        }

        return $this->render('@storefront/admin/orders/board.html.twig', ['columns' => $columns]);
    }

    /** One card moved to another stage: the same change (and the same customer notice) as on the order page. */
    #[Route('/admin/orders/board/move', name: 'admin_order_board_move', methods: ['POST'], priority: 30)]
    public function move(Request $request): JsonResponse
    {
        $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_order_board', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['ok' => false, 'message' => CanonicalUiText::get('common.security.invalid_csrf')], 403);
        }
        $data = json_decode($request->getContent(), true);
        $public = is_array($data) ? (string) ($data['order'] ?? '') : '';
        $stage = is_array($data) ? (string) ($data['stage'] ?? '') : '';
        if (preg_match('/^[0-9a-f-]{36}$/i', $public) !== 1 || !isset(self::COLUMNS[$stage]) || $stage === 'new') {
            return new JsonResponse(['ok' => false, 'message' => CanonicalUiText::get('admin.board.bad_move')], 422);
        }
        $storeId = $this->contexts->resolve($request)->storeId;
        $owned = $this->db->fetchOne('SELECT 1 FROM mc_sales_order WHERE public_id=? AND store_id=?', [Uuid::fromString($public)->toBinary(), $storeId]);
        if ($owned === false) {
            return new JsonResponse(['ok' => false, 'message' => CanonicalUiText::get('admin.board.bad_move')], 404);
        }
        try {
            $user = $this->getUser();
            $this->orders->updateFulfillment($public, $stage, null, $user instanceof AdminUser ? $user->displayName : 'admin');
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'message' => $e instanceof \DomainException || $e instanceof \InvalidArgumentException ? $e->getMessage() : CanonicalUiText::get('admin.board.bad_move')], 409);
        }
        try {
            $this->notifications->enqueueCurrentStatus($public);
        } catch (\Throwable) {
            // A notice that cannot be queued never undoes the move.
        }

        return new JsonResponse(['ok' => true]);
    }

    /** A page of 100 x 100 mm waybill stickers (one per selected order) ready for the label printer. */
    #[Route('/admin/orders/stickers', name: 'admin_order_stickers', methods: ['POST'], priority: 30)]
    public function stickers(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_order_bulk', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $ids = array_slice(array_values(array_unique(array_filter(array_map('strval', (array) $request->request->all('order_ids')), static fn (string $id): bool => preg_match('/^[0-9a-f-]{36}$/i', $id) === 1))), 0, 100);
        $stickers = [];
        $store = (string) ($this->db->fetchOne('SELECT name FROM mc_store WHERE id=?', [$context->storeId]) ?: '');
        foreach ($ids as $public) {
            $o = $this->db->fetchAssociative(
                "SELECT o.id,o.order_number,o.customer_name,o.customer_phone,o.total_minor,o.currency,o.payment_status,o.customer_comment,
                        (SELECT p.provider_code FROM mc_payment p WHERE p.order_id=o.id ORDER BY p.id DESC LIMIT 1) AS payment_provider
                 FROM mc_sales_order o WHERE o.public_id=? AND o.store_id=?",
                [Uuid::fromString($public)->toBinary(), $context->storeId],
            );
            if (!is_array($o)) {
                continue;
            }
            $f = $this->db->fetchAssociative('SELECT provider_code,tracking_number,destination_snapshot FROM mc_fulfillment WHERE order_id=? ORDER BY id DESC LIMIT 1', [(int) $o['id']]) ?: [];
            $d = json_decode((string) ($f['destination_snapshot'] ?? '{}'), true);
            $d = is_array($d) ? $d : [];
            $to = trim(implode(', ', array_filter([(string) ($d['manual'] ?? ''), (string) ($d['city'] ?? ''), (string) ($d['point'] ?? ''), (string) ($d['address'] ?? '')])));
            $cod = (string) $o['payment_provider'] === 'cash_on_delivery' && (string) $o['payment_status'] !== 'paid';
            $stickers[] = [
                'order' => (string) $o['order_number'], 'name' => (string) $o['customer_name'], 'phone' => (string) $o['customer_phone'], 'to' => $to,
                'carrier' => (string) ($f['provider_code'] ?? ''), 'tracking' => (string) ($f['tracking_number'] ?? ''), 'cod' => $cod ? (int) $o['total_minor'] : 0, 'currency' => (string) $o['currency'],
                'comment' => mb_substr((string) $o['customer_comment'], 0, 120),
                'barcode' => 'data:image/svg+xml;base64,' . base64_encode(\Commerce\Modules\OrderDocument\Application\Code128::svg((string) ($f['tracking_number'] ?? '') !== '' ? (string) $f['tracking_number'] : (string) $o['order_number'], 60)),
            ];
        }
        if ($stickers === []) {
            $this->addFlash('error', CanonicalUiText::get('admin.orders.bulk.nothing'));

            return $this->redirectToRoute('admin_orders');
        }

        return $this->render('@storefront/admin/orders/stickers.html.twig', ['stickers' => $stickers, 'store' => $store]);
    }
}
