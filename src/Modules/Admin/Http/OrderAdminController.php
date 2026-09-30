<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Order\Application\OrderManagementService;
use Commerce\Modules\Order\Application\OrderNotificationService;
use Commerce\Modules\OrderDocument\Application\OrderDocumentService;
use Commerce\Modules\Payment\Application\PaymentCancellationService;
use Commerce\Modules\Payment\Application\PaymentLifecycleService;
use Commerce\Modules\Payment\Application\PaymentRefundService;
use Commerce\Modules\Shipping\Application\ShipmentOperationService;
use Commerce\Modules\Shipping\Application\NovaPostShipmentOperationService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class OrderAdminController extends AbstractController
{
    private const ORDER_STATUSES = ['placed', 'awaiting_payment', 'confirmed', 'processing', 'payment_failed', 'completed', 'cancelled', 'refunded'];
    private const PAYMENT_STATUSES = ['pending', 'processing', 'authorized', 'paid', 'partially_refunded', 'refunded', 'failed', 'expired', 'cancelled'];
    private const FULFILLMENT_STATUSES = ['unfulfilled', 'pending', 'preparing', 'ready_for_pickup', 'shipped', 'delivered', 'not_required', 'cancelled'];

    public function __construct(
        private readonly Connection $db,
        private readonly AdminContextResolver $contexts,
        private readonly PaymentLifecycleService $lifecycle,
        private readonly PaymentRefundService $refunds,
        private readonly PaymentCancellationService $cancellations,
        private readonly OrderManagementService $orders,
        private readonly OrderNotificationService $notifications,
        private readonly OrderDocumentService $documents,
        private readonly ShipmentOperationService $shipments,
        private readonly NovaPostShipmentOperationService $novaPostShipments,
    ) {}

    #[Route('/admin/orders', name: 'admin_orders', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $ctx = $this->contexts->resolve($request);
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = (int) $request->query->get('limit', 25);
        if (!in_array($limit, [25, 50, 100], true)) {
            $limit = 25;
        }
        $offset = ($page - 1) * $limit;
        $search = trim((string) $request->query->get('search', ''));
        $status = trim((string) $request->query->get('status', ''));
        $paymentStatus = trim((string) $request->query->get('payment_status', ''));
        $fulfillmentStatus = trim((string) $request->query->get('fulfillment_status', ''));
        if (!in_array($status, self::ORDER_STATUSES, true)) $status = '';
        if (!in_array($paymentStatus, self::PAYMENT_STATUSES, true)) $paymentStatus = '';
        if (!in_array($fulfillmentStatus, self::FULFILLMENT_STATUSES, true)) $fulfillmentStatus = '';

        $where = ['o.store_id=?'];
        $params = [$ctx->storeId];
        if ($search !== '') {
            $where[] = '(o.order_number LIKE ? OR o.customer_phone LIKE ? OR o.customer_email_normalized LIKE ? OR o.customer_name LIKE ?)';
            $q = '%' . $search . '%';
            array_push($params, $q, $q, mb_strtolower($q), $q);
        }
        if ($status !== '') {
            $where[] = 'o.status=?';
            $params[] = $status;
        }
        if ($paymentStatus !== '') {
            $where[] = 'o.payment_status=?';
            $params[] = $paymentStatus;
        }
        if ($fulfillmentStatus !== '') {
            $where[] = 'o.fulfillment_status=?';
            $params[] = $fulfillmentStatus;
        }
        $sqlWhere = implode(' AND ', $where);

        $count = (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_sales_order o WHERE ' . $sqlWhere, $params);
        $pages = max(1, (int) ceil($count / $limit));
        if ($page > $pages) {
            $page = $pages;
            $offset = ($page - 1) * $limit;
        }

        $sortColumns = ['order' => 'o.order_number', 'customer' => 'o.customer_name', 'payment' => 'o.payment_status', 'fulfillment' => 'o.fulfillment_status', 'total' => 'o.total_minor', 'date' => 'o.created_at'];
        $sortKey = (string) $request->query->get('sort', '');
        $orderBy = isset($sortColumns[$sortKey]) ? $sortColumns[$sortKey] . ' ' . (strtolower((string) $request->query->get('dir', 'desc')) === 'asc' ? 'ASC' : 'DESC') . ',o.id DESC' : 'o.id DESC';
        $rows = $this->db->fetchAllAssociative(
            'SELECT o.id,o.public_id,o.order_number,o.status,o.payment_status,o.fulfillment_status,o.total_minor,o.currency,
                    o.customer_name,o.customer_phone,o.customer_email,o.created_at,
                    (SELECT p.provider_code FROM mc_payment p WHERE p.order_id=o.id ORDER BY p.id DESC LIMIT 1) provider_code
             FROM mc_sales_order o
             WHERE ' . $sqlWhere . '
             ORDER BY ' . $orderBy . '
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
        );
        foreach ($rows as &$row) {
            $row['public_id'] = Uuid::fromBinary((string) $row['public_id'])->toRfc4122();
            $row['total_display'] = $this->money((int) $row['total_minor'], (string) $row['currency']);
        }
        unset($row);

        $allowedColumns=['order','customer','payment','fulfillment','total','date'];
        $activeColumns=array_values(array_intersect($allowedColumns,array_map('strval',(array)$request->query->all('columns'))));
        if($activeColumns===[])$activeColumns=$allowedColumns;
        $views=[];
        $selectedView=max(0,(int)$request->query->get('view',0));
        try{
            $user=$this->getUser();$adminId=$user instanceof AdminUser?$user->id:null;
            $saved=$this->db->fetchAllAssociative('SELECT id,name,filters_json,columns_json FROM mc_admin_saved_view WHERE store_id=? AND entity_type=? AND (admin_id=? OR admin_id IS NULL) ORDER BY id DESC LIMIT 30',[$ctx->storeId,'orders',$adminId]);
            foreach($saved as $savedRow){$filters=[];$columns=[];try{$d=json_decode((string)$savedRow['filters_json'],true,16,JSON_THROW_ON_ERROR);if(is_array($d))$filters=$d;}catch(\Throwable){}try{$d=json_decode((string)$savedRow['columns_json'],true,16,JSON_THROW_ON_ERROR);if(is_array($d))$columns=array_values(array_intersect($allowedColumns,array_map('strval',$d)));}catch(\Throwable){}if($columns===[])$columns=$allowedColumns;$views[]=['id'=>(int)$savedRow['id'],'name'=>(string)$savedRow['name'],'filters'=>$filters,'columns'=>$columns];if($selectedView===(int)$savedRow['id'])$activeColumns=$columns;}
        }catch(\Throwable){}
        return $this->render('@storefront/admin/orders/index.html.twig', [
            'orders' => $rows,
            'search' => $search,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'fulfillment_status' => $fulfillmentStatus,
            'order_statuses' => self::ORDER_STATUSES,
            'payment_statuses' => self::PAYMENT_STATUSES,
            'fulfillment_statuses' => self::FULFILLMENT_STATUSES,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
            'count' => $count,
            'saved_views'=>$views,
            'active_columns'=>$activeColumns,
            'allowed_columns'=>$allowedColumns,
            'selected_view'=>$selectedView,
        ]);
    }


    #[Route('/admin/orders/views/save', name:'admin_order_saved_view_save', methods:['POST'])]
    public function saveView(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request);
        if(!$this->isCsrfTokenValid('admin_order_saved_view_save',(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
        $name=trim(strip_tags((string)$request->request->get('name','')));if($name===''||mb_strlen($name)>190){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.orderadmincontroller.vkazhit_nazvu_predstavlennia'));return $this->redirectToRoute('admin_orders');}
        $filters=['search'=>mb_substr(trim((string)$request->request->get('search','')),0,190),'status'=>(string)$request->request->get('status',''),'payment_status'=>(string)$request->request->get('payment_status',''),'fulfillment_status'=>(string)$request->request->get('fulfillment_status',''),'limit'=>(int)$request->request->get('limit',25)];
        $allowed=['order','customer','payment','fulfillment','total','date'];$columns=array_values(array_intersect($allowed,array_map('strval',(array)$request->request->all('columns'))));if($columns===[])$columns=$allowed;
        $user=$this->getUser();$adminId=$user instanceof AdminUser?$user->id:null;$now=gmdate('Y-m-d H:i:s.u');
        $this->db->insert('mc_admin_saved_view',['store_id'=>$ctx->storeId,'admin_id'=>$adminId,'entity_type'=>'orders','name'=>$name,'filters_json'=>json_encode($filters,JSON_THROW_ON_ERROR),'columns_json'=>json_encode($columns,JSON_THROW_ON_ERROR),'is_default'=>0,'created_at'=>$now,'updated_at'=>$now]);
        $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.orderadmincontroller.predstavlennia_zamovlen_zberezheno'));return $this->redirectToRoute('admin_orders');
    }

    #[Route('/admin/orders/views/{id}/delete', name:'admin_order_saved_view_delete', methods:['POST'], requirements:['id'=>'\\d+'])]
    public function deleteView(int $id, Request $request): Response
    {
        $ctx=$this->contexts->resolve($request);if(!$this->isCsrfTokenValid('admin_order_saved_view_delete_'.$id,(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();$user=$this->getUser();$adminId=$user instanceof AdminUser?$user->id:null;$this->db->executeStatement('DELETE FROM mc_admin_saved_view WHERE id=? AND store_id=? AND entity_type=? AND (admin_id=? OR admin_id IS NULL)',[$id,$ctx->storeId,'orders',$adminId]);return $this->redirectToRoute('admin_orders');
    }

    /** Bulk actions on ticked orders: mark completed or move the delivery status. Each order goes through the same service as the single-order button. */
    #[Route('/admin/orders/bulk', name: 'admin_order_bulk', methods: ['POST'])]
    public function bulk(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_order_bulk', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $action = (string) $request->request->get('action', '');
        $ids = array_slice(array_values(array_unique(array_filter(array_map('strval', (array) $request->request->all('order_ids')), static fn (string $id): bool => preg_match('/^[0-9a-f-]{36}$/i', $id) === 1))), 0, 100);
        $fulfillment = str_starts_with($action, 'fulfillment:') ? substr($action, 12) : null;
        if ($ids === [] || ($action !== 'complete' && $fulfillment === null)) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('admin.orders.bulk.nothing'));

            return $this->redirectToRoute('admin_orders');
        }
        $done = 0;
        $failed = 0;
        foreach ($ids as $publicId) {
            try {
                $this->order($publicId, $request);
                if ($fulfillment !== null) {
                    $this->orders->updateFulfillment($publicId, $fulfillment, null, $this->actor());
                } else {
                    $this->orders->markCompleted($publicId, $this->actor());
                }
                $this->notifyFailSoft($publicId);
                ++$done;
            } catch (\Throwable) {
                ++$failed;
            }
        }
        $this->addFlash($failed === 0 ? 'success' : 'error', \Commerce\Core\I18n\CanonicalUiText::get('admin.orders.bulk.result', ['done' => (string) $done, 'failed' => (string) $failed]));

        return $this->redirectToRoute('admin_orders');
    }

    #[Route('/admin/orders/{publicId}/preview', name:'admin_order_preview', methods:['GET'])]
    public function preview(string $publicId, Request $request): Response
    {
        $order=$this->order($publicId,$request);$items=$this->db->fetchAllAssociative('SELECT sku,name,quantity,line_total_minor FROM mc_sales_order_item WHERE order_id=? ORDER BY id LIMIT 8',[(int)$order['id']]);foreach($items as &$item){$item['line_total_display']=$this->money((int)$item['line_total_minor'],(string)$order['currency']);}unset($item);return $this->render('@storefront/admin/orders/_preview.html.twig',['order'=>$order,'items'=>$items]);
    }

    #[Route('/admin/orders/{publicId}', name: 'admin_order_view', methods: ['GET'])]
    public function view(string $publicId, Request $request): Response
    {
        $order = $this->order($publicId, $request);
        $items = $this->db->fetchAllAssociative('SELECT * FROM mc_sales_order_item WHERE order_id=? ORDER BY id', [(int) $order['id']]);
        $payment = $this->db->fetchAssociative('SELECT * FROM mc_payment WHERE order_id=? ORDER BY id DESC LIMIT 1', [(int) $order['id']]) ?: [];
        $fulfillment = $this->db->fetchAssociative('SELECT * FROM mc_fulfillment WHERE order_id=? ORDER BY id DESC LIMIT 1', [(int) $order['id']]) ?: [];
        if ($fulfillment !== []) {
            $fulfillment['destination'] = $this->decodeJsonObject((string) ($fulfillment['destination_snapshot'] ?? ''));
        }
        $refunds = [];
        if ($payment !== []) {
            $refunds = $this->db->fetchAllAssociative('SELECT * FROM mc_payment_refund WHERE payment_id=? ORDER BY id DESC', [(int) $payment['id']]);
            foreach ($refunds as &$refund) {
                $refund['amount_display'] = $this->money((int) $refund['amount_minor'], (string) $order['currency']);
            }
            unset($refund);
        }
        $events = $this->db->fetchAllAssociative('SELECT * FROM mc_order_event WHERE order_id=? ORDER BY sequence_no DESC', [(int) $order['id']]);
        foreach ($events as &$event) {
            $event['payload_data'] = $this->decodeJsonObject((string) ($event['payload'] ?? ''));
        }
        unset($event);
        $notificationRows = $this->db->fetchAllAssociative(
            "SELECT channel,notification_type,recipient,status,attempts,created_at,sent_at,last_error
             FROM mc_notification_outbox
             WHERE notification_type LIKE 'order.%' AND payload LIKE ?
             ORDER BY id DESC LIMIT 25",
            ['%"order_number":"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $order['order_number']) . '"%'],
        );

        $refundedMinor = (int) ($payment['refunded_minor'] ?? 0);
        $paymentAmount = (int) ($payment['amount_minor'] ?? 0);
        $pendingRefundMinor = 0;
        foreach ($refunds as $refund) {
            if (in_array((string) $refund['status'], ['pending', 'processing'], true)) {
                $pendingRefundMinor += (int) $refund['amount_minor'];
            }
        }
        $order['refundable_minor'] = max(0, $paymentAmount - $refundedMinor - $pendingRefundMinor);
        $order['refundable_display'] = $this->money($order['refundable_minor'], (string) $order['currency']);

        return $this->render('@storefront/admin/orders/view.html.twig', [
            'order' => $order,
            'items' => $items,
            'payment' => $payment,
            'fulfillment' => $fulfillment,
            'refunds' => $refunds,
            'events' => $events,
            'notifications' => $notificationRows,
            'documents' => $this->documents->listForOrder($this->contexts->resolve($request)->storeId, $publicId),
            'shipments' => $this->shipments->listForOrder($this->contexts->resolve($request)->storeId, $publicId),
            'nova_post_api_configured' => $this->novaPostShipments->configured(),
            'fulfillment_statuses' => ['pending', 'preparing', 'ready_for_pickup', 'shipped', 'delivered', 'cancelled'],
        ]);
    }

    #[Route('/admin/orders/{publicId}/mark-paid', name: 'admin_order_mark_paid', methods: ['POST'])]
    public function markPaid(string $publicId, Request $request): Response
    {
        $this->csrf($publicId, $request);
        try {
            $order = $this->order($publicId, $request);
            if (!in_array((string) ($order['provider_code'] ?? ''), ['bank_transfer', 'cash_on_delivery'], true)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.orderadmincontroller.ruchne_pidtverdzhennia_dostupne_lyshe_dlia_bankivsko'));
            }
            $this->lifecycle->markManualPaid($publicId, $this->actor());
            $this->notifyFailSoft($publicId);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.orderadmincontroller.oplatu_pidtverdzheno'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }
        return $this->redirectToRoute('admin_order_view', ['publicId' => $publicId]);
    }

    #[Route('/admin/orders/{publicId}/fulfillment', name: 'admin_order_fulfillment', methods: ['POST'])]
    public function fulfillment(string $publicId, Request $request): Response
    {
        $this->csrf($publicId, $request);
        try {
            $this->orders->updateFulfillment(
                $publicId,
                (string) $request->request->get('status', ''),
                (string) $request->request->get('tracking_number', ''),
                $this->actor(),
            );
            $this->notifyFailSoft($publicId);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.orderadmincontroller.status_dostavky_onovleno'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }
        return $this->redirectToRoute('admin_order_view', ['publicId' => $publicId]);
    }

    #[Route('/admin/orders/{publicId}/note', name: 'admin_order_note', methods: ['POST'])]
    public function note(string $publicId, Request $request): Response
    {
        $this->csrf($publicId, $request);
        try {
            $this->orders->addInternalNote($publicId, (string) $request->request->get('note', ''), $this->actor());
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.orderadmincontroller.vnutrishniu_notatku_dodano'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }
        return $this->redirectToRoute('admin_order_view', ['publicId' => $publicId]);
    }

    #[Route('/admin/orders/{publicId}/complete', name: 'admin_order_complete', methods: ['POST'])]
    public function complete(string $publicId, Request $request): Response
    {
        $this->csrf($publicId, $request);
        try {
            $this->orders->markCompleted($publicId, $this->actor());
            $this->notifyFailSoft($publicId);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.orderadmincontroller.zamovlennia_zaversheno'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }
        return $this->redirectToRoute('admin_order_view', ['publicId' => $publicId]);
    }

    #[Route('/admin/orders/{publicId}/cancel', name: 'admin_order_cancel', methods: ['POST'])]
    public function cancel(string $publicId, Request $request): Response
    {
        $this->csrf($publicId, $request);
        try {
            $this->cancellations->cancelProviderPayment($publicId);
            $this->lifecycle->cancelOrder($publicId, $this->actor());
            $this->notifyFailSoft($publicId);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.orderadmincontroller.zamovlennia_skasovano_aktyvnyi_rezerv_zvilneno'));
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof \DomainException ? $e->getMessage() : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.orderadmincontroller.platizhnyi_provaider_ne_pidtverdyv_skasuvannia_zamov'));
        }
        return $this->redirectToRoute('admin_order_view', ['publicId' => $publicId]);
    }

    #[Route('/admin/orders/{publicId}/refund', name: 'admin_order_refund', methods: ['POST'])]
    public function refund(string $publicId, Request $request): Response
    {
        $this->csrf($publicId, $request);
        try {
            $amount = trim((string) $request->request->get('amount', ''));
            if ($amount === '') {
                $this->refunds->refundFull($publicId, $this->actor());
            } else {
                $this->refunds->refundAmount($publicId, $this->parseMoneyToMinor($amount), $this->actor());
            }
            $this->notifyFailSoft($publicId);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.orderadmincontroller.zapyt_na_povernennia_peredano_platizhnomu_provaideru'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }
        return $this->redirectToRoute('admin_order_view', ['publicId' => $publicId]);
    }

    #[Route('/admin/orders/{publicId}/notify', name: 'admin_order_notify', methods: ['POST'])]
    public function notifyCustomer(string $publicId, Request $request): Response
    {
        $this->csrf($publicId, $request);
        try {
            if (!$this->notifications->enqueueCurrentStatus($publicId)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.orderadmincontroller.u_zamovlennia_nemaie_korektnoho_email_pokuptsia'));
            }
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.orderadmincontroller.onovlennia_zamovlennia_postavleno_v_cherhu_na_vidpra'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.orderadmincontroller.ne_vdalosia_postavyty_povidomlennia_v_cherhu_zamovle'));
        }
        return $this->redirectToRoute('admin_order_view', ['publicId' => $publicId]);
    }

    /** @return array<string,mixed> */
    private function order(string $publicId, Request $request): array
    {
        $ctx=$this->contexts->resolve($request);
        try {
            $binary = Uuid::fromString($publicId)->toBinary();
        } catch (\Throwable) {
            throw $this->createNotFoundException();
        }
        $row = $this->db->fetchAssociative(
            'SELECT o.*,(SELECT p.provider_code FROM mc_payment p WHERE p.order_id=o.id ORDER BY p.id DESC LIMIT 1) provider_code
             FROM mc_sales_order o WHERE o.public_id=? AND o.store_id=? LIMIT 1',
            [$binary,$ctx->storeId],
        );
        if (!is_array($row)) {
            throw $this->createNotFoundException();
        }
        $row['public_id_text'] = $publicId;
        $row['total_display'] = $this->money((int) $row['total_minor'], (string) $row['currency']);
        return $row;
    }

    private function csrf(string $id, Request $request): void
    {
        $this->order($id,$request);
        if (!$this->isCsrfTokenValid('admin_order_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }

    private function actor(): string
    {
        $user = $this->getUser();
        return $user instanceof AdminUser ? $user->displayName : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.http.adminorderdocumentcontroller.administrator');
    }

    private function notifyFailSoft(string $publicId): void
    {
        try {
            $this->notifications->enqueueCurrentStatus($publicId);
        } catch (\Throwable) {
            // Notification failure must never roll back a successful order operation.
        }
    }

    private function parseMoneyToMinor(string $value): int
    {
        $value = str_replace([' ', ','], ['', '.'], trim($value));
        if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $value)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.orderadmincontroller.nekorektna_suma_povernennia'));
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');
        $minor = ((int) $whole * 100) + (int) substr($fraction, 0, 2);
        if ($minor <= 0) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.orderadmincontroller.suma_povernennia_maie_buty_bilshoiu_za_nul'));
        }
        return $minor;
    }

    private function money(int $minor, string $currency): string
    {
        return number_format($minor / 100, 2, ',', ' ') . ' ' . $currency;
    }

    /** @return array<string,mixed> */
    private function decodeJsonObject(string $json): array
    {
        if ($json === '') return [];
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
