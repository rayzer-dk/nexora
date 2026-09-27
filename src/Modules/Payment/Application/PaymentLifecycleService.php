<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Application;

use Commerce\Core\Event\DomainEventFactory;
use Commerce\Core\Event\EventBusInterface;
use Commerce\Core\Event\EventNames;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Commerce\Modules\Rewards\Application\GiftCardService;
use Commerce\Modules\Rewards\Application\LoyaltyService;
use Symfony\Component\Uid\Uuid;

/**
 * Owns payment/order/inventory state transitions. Provider callbacks only submit facts here.
 * All methods are retry-safe: duplicate webhooks/manual actions do not double-decrement stock.
 */
final readonly class PaymentLifecycleService
{
    public function __construct(
        private Connection $db,
        private EventBusInterface $events,
        private DomainEventFactory $eventFactory,
        private GiftCardService $giftCards,
        private LoyaltyService $loyalty,
    ) {}

    public function confirmCashOnDelivery(string $orderPublicId): void
    {
        $this->db->transactional(function(Connection $db) use($orderPublicId): void {
            $order = $this->lockOrderByPublicId($db, $orderPublicId);
            if (in_array((string)$order['status'], ['confirmed','completed'], true)) return;
            $this->commitReservations($db, (int)$order['id']);
            $now=$this->now();
            $db->update('mc_sales_order',['status'=>'confirmed','payment_status'=>'pending','updated_at'=>$now],['id'=>(int)$order['id']]);
            $this->appendEvent($db,(int)$order['id'],'order.confirmed_cod',[],'system','payment');
        });
    }

    public function confirmDeferredPayment(string $orderPublicId, string $actor = 'b2b'): void
    {
        $this->db->transactional(function(Connection $db) use($orderPublicId,$actor): void {
            $order=$this->lockOrderByPublicId($db,$orderPublicId);
            if (in_array((string)$order['status'],['confirmed','completed'],true)) return;
            if (in_array((string)$order['status'],['cancelled','refunded'],true)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentlifecycleservice.skasovane_zamovlennia_ne_mozhna_pidtverdyty'));
            $this->commitReservations($db,(int)$order['id']); $now=$this->now();
            $db->update('mc_sales_order',['status'=>'confirmed','payment_status'=>'pending','updated_at'=>$now],['id'=>(int)$order['id']]);
            $this->appendEvent($db,(int)$order['id'],'order.confirmed_deferred_payment',[],'system',$actor);
        });
    }

    public function markManualPaid(string $orderPublicId, string $actor): void
    {
        $this->db->transactional(function(Connection $db) use($orderPublicId,$actor): void {
            $order=$this->lockOrderByPublicId($db,$orderPublicId);
            if ((string)$order['payment_status']==='paid') return;
            if (in_array((string)$order['status'],['cancelled','refunded'],true)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentlifecycleservice.skasovane_zamovlennia_ne_mozhna_pidtverdyty_iak_opla'));
            $payment=$db->fetchAssociative('SELECT * FROM mc_payment WHERE order_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE',[(int)$order['id']]);
            if(!is_array($payment)) throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentlifecycleservice.platizh_zamovlennia_ne_znaideno'));
            $this->commitReservations($db,(int)$order['id']); $now=$this->now();
            $db->update('mc_payment',['status'=>'paid','paid_at'=>$now,'updated_at'=>$now],['id'=>(int)$payment['id']]);
            $db->update('mc_sales_order',['status'=>'confirmed','payment_status'=>'paid','updated_at'=>$now],['id'=>(int)$order['id']]);
            $this->activateDigitalEntitlements($db,(int)$order['id'],$now);
            $this->loyalty->earnForPaidOrder($db,(int)$order['id']);
            $this->appendEvent($db,(int)$order['id'],'payment.manual_paid',[],'admin',$actor);
            $this->events->publish($this->eventFactory->create(EventNames::PAYMENT_STATUS_CHANGED, 'order', $orderPublicId, ['status'=>'paid','provider'=>'manual'], ['actor'=>'admin']));
        });
    }

    public function cancelOrder(string $orderPublicId, string $actor): void
    {
        $this->db->transactional(function(Connection $db) use($orderPublicId,$actor): void {
            $order=$this->lockOrderByPublicId($db,$orderPublicId);
            if ((string)$order['status']==='cancelled') return;
            if ((string)$order['payment_status']==='paid') throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentlifecycleservice.spochatku_vykonaite_povernennia_oplaty'));
            $this->releaseReservations($db,(int)$order['id']); $now=$this->now();
            $db->update('mc_sales_order',['status'=>'cancelled','updated_at'=>$now],['id'=>(int)$order['id']]);
            $this->giftCards->restoreForOrder($db,(int)$order['id'],'cancel');
            $this->loyalty->restoreSpendForOrder($db,(int)$order['id'],'cancel');
            $db->update('mc_payment',['status'=>'cancelled','cancelled_at'=>$now,'updated_at'=>$now],['order_id'=>(int)$order['id']]);
            $this->appendEvent($db,(int)$order['id'],'order.cancelled',[],'admin',$actor);
            $this->events->publish($this->eventFactory->create(EventNames::ORDER_CANCELLED, 'order', $orderPublicId, ['reason'=>'admin_cancel'], ['actor'=>'admin']));
        });
    }

    /** @param array<string,mixed> $payload */
    public function applyProviderStatus(string $providerCode, string $providerReference, string $providerStatus, ?string $providerModifiedAt, int $amountMinor, string $currency, ?string $failureReason, array $payload): void
    {
        $this->db->transactional(function(Connection $db) use($providerCode,$providerReference,$providerStatus,$providerModifiedAt,$amountMinor,$currency,$failureReason,$payload): void {
            $payment=$db->fetchAssociative('SELECT p.*,o.public_id order_public_id,o.status order_status,o.payment_status order_payment_status,o.total_minor order_total,o.currency order_currency FROM mc_payment p JOIN mc_sales_order o ON o.id=p.order_id WHERE p.provider_code=? AND p.provider_reference=? LIMIT 1 FOR UPDATE',[$providerCode,$providerReference]);
            if(!is_array($payment)) throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentlifecycleservice.platizh_dlia_webhook_ne_znaideno'));
            $normalizedProviderDate=$this->normalizeProviderDate($providerModifiedAt);
            if($normalizedProviderDate!==null && $payment['provider_modified_at']!==null && strcmp($normalizedProviderDate,(string)$payment['provider_modified_at'])<=0) return;
            if(in_array((string)$payment['status'],['refunded','partially_refunded'],true) && $providerStatus!=='reversed') return;
            if((string)$payment['status']==='paid' && $providerStatus!=='reversed') return;
            if($providerStatus==='success' && ($amountMinor!==(int)$payment['order_total'] || strtoupper($currency)!==strtoupper((string)$payment['order_currency']))) throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentlifecycleservice.suma_abo_valiuta_platezhu_ne_vidpovidaie_zamovlenniu'));
            $orderId=(int)$payment['order_id']; $now=$this->now();
            $paymentUpdate=['provider_modified_at'=>$normalizedProviderDate,'provider_payload'=>json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'failure_reason'=>$failureReason,'updated_at'=>$now];
            if($providerStatus==='success') {
                if((string)$payment['status']!=='paid') $this->commitReservations($db,$orderId);
                $paymentUpdate += ['status'=>'paid','paid_at'=>$now];
                $db->update('mc_sales_order',['status'=>'confirmed','payment_status'=>'paid','updated_at'=>$now],['id'=>$orderId]);
                $this->activateDigitalEntitlements($db,$orderId,$now);
                $this->loyalty->earnForPaidOrder($db,$orderId);
                $event='payment.paid';
            } elseif(in_array($providerStatus,['failure','expired'],true)) {
                if(!in_array((string)$payment['status'],['paid','refunded'],true)) $this->releaseReservations($db,$orderId);
                $this->giftCards->restoreForOrder($db,$orderId,$providerStatus);
                $this->loyalty->restoreSpendForOrder($db,$orderId,$providerStatus);
                $paymentUpdate += ['status'=>$providerStatus==='expired'?'expired':'failed','failed_at'=>$now];
                $db->update('mc_sales_order',['status'=>$providerStatus==='expired'?'cancelled':'payment_failed','payment_status'=>$providerStatus==='expired'?'expired':'failed','updated_at'=>$now],['id'=>$orderId]);
                $event=$providerStatus==='expired'?'payment.expired':'payment.failed';
            } elseif($providerStatus==='reversed') {
                $paymentUpdate += ['status'=>'refunded','refunded_minor'=>(int)$payment['amount_minor']];
                $db->update('mc_sales_order',['status'=>'refunded','payment_status'=>'refunded','updated_at'=>$now],['id'=>$orderId]);
                $db->executeStatement("UPDATE mc_digital_entitlement SET status='revoked',updated_at=? WHERE order_id=? AND status IN ('pending','active')",[$now,$orderId]);
                $this->giftCards->restoreForOrder($db,$orderId,'refund');
                $this->loyalty->reverseEarnForOrder($db,$orderId);
                $this->loyalty->restoreSpendForOrder($db,$orderId,'refund');
                $event='payment.refunded';
            } else {
                $paymentUpdate += ['status'=>$providerStatus==='hold'?'authorized':'processing'];
                $db->update('mc_sales_order',['payment_status'=>$providerStatus==='hold'?'authorized':'processing','updated_at'=>$now],['id'=>$orderId]);
                $event='payment.'.$providerStatus;
            }
            $db->update('mc_payment',$paymentUpdate,['id'=>(int)$payment['id']]);
            $this->appendEvent($db,$orderId,$event,['provider'=>$providerCode,'provider_status'=>$providerStatus],'provider',$providerCode);
            $this->events->publish($this->eventFactory->create(
                EventNames::PAYMENT_STATUS_CHANGED,
                'order',
                Uuid::fromBinary((string)$payment['order_public_id'])->toRfc4122(),
                ['status'=>(string)$paymentUpdate['status'],'provider'=>$providerCode,'provider_status'=>$providerStatus],
                ['source'=>'payment_provider'],
            ));
        });
    }

    public function expireUnpaidReservations(int $limit=100): int
    {
        $ids=$this->db->fetchFirstColumn("SELECT DISTINCT order_id FROM mc_inventory_reservation WHERE status='active' AND order_id IS NOT NULL AND expires_at IS NOT NULL AND expires_at<=UTC_TIMESTAMP(6) ORDER BY order_id LIMIT ".max(1,min($limit,1000)));
        $count=0;
        foreach($ids as $orderId){
            $this->db->transactional(function(Connection $db) use($orderId,&$count): void {
                $order=$db->fetchAssociative('SELECT * FROM mc_sales_order WHERE id=? FOR UPDATE',[(int)$orderId]);
                if(!is_array($order) || in_array((string)$order['payment_status'],['paid','refunded'],true)) return;
                $this->releaseReservations($db,(int)$orderId); $now=$this->now();
                $this->giftCards->restoreForOrder($db,(int)$orderId,'expired');
                $this->loyalty->restoreSpendForOrder($db,(int)$orderId,'expired');
                $db->update('mc_sales_order',['status'=>'cancelled','payment_status'=>'expired','updated_at'=>$now],['id'=>(int)$orderId]);
                $db->update('mc_payment',['status'=>'expired','failed_at'=>$now,'updated_at'=>$now],['order_id'=>(int)$orderId]);
                $this->appendEvent($db,(int)$orderId,'order.expired',[],'system','reservation_expiry');
                $this->events->publish($this->eventFactory->create(EventNames::ORDER_CANCELLED, 'order', Uuid::fromBinary((string)$order['public_id'])->toRfc4122(), ['reason'=>'payment_expired'], ['actor'=>'system']));
                $count++;
            });
        }
        return $count;
    }

    /** @return array<string,mixed> */
    private function lockOrderByPublicId(Connection $db,string $id): array
    {
        $row=$db->fetchAssociative('SELECT * FROM mc_sales_order WHERE public_id=? FOR UPDATE',[Uuid::fromString($id)->toBinary()]);
        if(!is_array($row)) throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.zamovlennia_ne_znaideno')); return $row;
    }

    private function commitReservations(Connection $db,int $orderId): void
    {
        $rows=$db->fetchAllAssociative("SELECT * FROM mc_inventory_reservation WHERE order_id=? FOR UPDATE",[$orderId]); $now=$this->now();
        foreach($rows as $r){
            $status=(string)$r['status']; if($status==='committed') continue; $q=(string)$r['quantity'];
            if($status==='active') {
                $changed=$db->executeStatement('UPDATE mc_stock_level SET stocked_quantity=stocked_quantity-?,reserved_quantity=reserved_quantity-?,row_version=row_version+1,updated_at=? WHERE inventory_item_id=? AND location_id=? AND stocked_quantity>=? AND reserved_quantity>=?',[$q,$q,$now,(int)$r['inventory_item_id'],(int)$r['location_id'],$q,$q]);
            } elseif($status==='released') {
                $changed=$db->executeStatement('UPDATE mc_stock_level SET stocked_quantity=stocked_quantity-?,row_version=row_version+1,updated_at=? WHERE inventory_item_id=? AND location_id=? AND (stocked_quantity-reserved_quantity-safety_stock)>=?',[$q,$now,(int)$r['inventory_item_id'],(int)$r['location_id'],$q]);
            } else { continue; }
            if($changed!==1) throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentlifecycleservice.oplatu_otrymano_ale_zapas_uzhe_nedostupnyi_potribne_'));
            $db->update('mc_inventory_reservation',['status'=>'committed','committed_at'=>$now],['id'=>(int)$r['id']]);
        }
    }

    private function releaseReservations(Connection $db,int $orderId): void
    {
        $rows=$db->fetchAllAssociative("SELECT * FROM mc_inventory_reservation WHERE order_id=? AND status='active' FOR UPDATE",[$orderId]); $now=$this->now();
        foreach($rows as $r){ $q=(string)$r['quantity']; $changed=$db->executeStatement('UPDATE mc_stock_level SET reserved_quantity=reserved_quantity-?,row_version=row_version+1,updated_at=? WHERE inventory_item_id=? AND location_id=? AND reserved_quantity>=?',[$q,$now,(int)$r['inventory_item_id'],(int)$r['location_id'],$q]); if($changed!==1) throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentlifecycleservice.porusheno_invariant_skladskoho_rezervu')); $db->update('mc_inventory_reservation',['status'=>'released','released_at'=>$now],['id'=>(int)$r['id']]); }
    }

    private function activateDigitalEntitlements(Connection $db,int $orderId,string $now): void
    {
        $rows=$db->fetchAllAssociative("SELECT id,access_days FROM mc_digital_entitlement WHERE order_id=? AND status='pending' FOR UPDATE",[$orderId]);
        foreach($rows as $row){
            $expiresAt=null;
            if($row['access_days']!==null){
                $expiresAt=(new DateTimeImmutable($now,new DateTimeZone('UTC')))->modify('+'.max(1,(int)$row['access_days']).' days')->format('Y-m-d H:i:s.u');
            }
            $db->update('mc_digital_entitlement',['status'=>'active','activated_at'=>$now,'expires_at'=>$expiresAt,'updated_at'=>$now],['id'=>(int)$row['id']]);
        }
    }

    private function appendEvent(Connection $db,int $orderId,string $type,array $payload,string $actorType,string $actor): void
    {
        $next=(int)$db->fetchOne('SELECT COALESCE(MAX(sequence_no),0)+1 FROM mc_order_event WHERE order_id=?',[$orderId]);
        $db->insert('mc_order_event',['order_id'=>$orderId,'sequence_no'=>$next,'event_type'=>$type,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'actor_type'=>$actorType,'actor_subject'=>$actor,'created_at'=>$this->now()]);
    }

    private function normalizeProviderDate(?string $value): ?string
    {
        if($value===null || trim($value)==='') return null;
        try { return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); } catch(\Throwable){ return null; }
    }
    private function now(): string { return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'); }
}
