<?php

declare(strict_types=1);

namespace Commerce\Modules\Return\Application;

use Commerce\Core\Id\PublicIdFactory;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class ReturnRequestService
{
    public const STATUSES = ['requested','approved','rejected','in_transit','received','resolved','cancelled'];
    public const REASONS = ['damaged','wrong_item','not_as_described','defective','changed_mind','other'];
    public const RESOLUTIONS = ['refund','exchange','repair','store_credit','none'];

    private const TRANSITIONS = [
        'requested' => ['approved','rejected','cancelled'],
        'approved' => ['in_transit','received','cancelled'],
        'in_transit' => ['received','cancelled'],
        'received' => ['resolved'],
        'rejected' => [],
        'resolved' => [],
        'cancelled' => [],
    ];

    public function __construct(private readonly Connection $db, private readonly PublicIdFactory $ids) {}

    /** @param array<int,string> $quantities */
    public function create(int $storeId, int $customerId, string $orderPublicId, array $quantities, string $reason, string $note): string
    {
        if (!in_array($reason, self::REASONS, true)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.return.application.returnrequestservice.oberit_korektnu_prychynu_povernennia'));
        $note = mb_substr(trim(strip_tags($note)), 0, 4000);
        try { $orderBinary = Uuid::fromString($orderPublicId)->toBinary(); } catch (\Throwable) { throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.zamovlennia_ne_znaideno')); }
        $order = $this->db->fetchAssociative('SELECT id,status,fulfillment_status FROM mc_sales_order WHERE public_id=? AND store_id=? AND customer_id=? LIMIT 1', [$orderBinary,$storeId,$customerId]);
        if (!is_array($order)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.zamovlennia_ne_znaideno'));
        if (in_array((string)$order['status'], ['cancelled','payment_failed'], true)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.return.application.returnrequestservice.dlia_tsoho_zamovlennia_povernennia_nedostupne'));
        $fulfilled = in_array((string)$order['fulfillment_status'], ['shipped','delivered','fulfilled'], true) || in_array((string)$order['status'], ['completed','fulfilled'], true);
        if (!$fulfilled) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.return.application.returnrequestservice.zapyt_na_povernennia_dostupnyi_pislia_vidpravlennia_'));

        $items = $this->db->fetchAllAssociative('SELECT id,quantity FROM mc_sales_order_item WHERE order_id=? ORDER BY id', [(int)$order['id']]);
        $valid = [];
        foreach ($items as $item) {
            $id = (int) $item['id'];
            $rawQty = trim((string) ($quantities[$id] ?? '0'));
            if (!preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', $rawQty)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.return.application.returnrequestservice.nekorektna_kilkist_povernennia'));
            $qty = (float) $rawQty;
            $ordered = (float) $item['quantity'];
            $alreadyRequested = (float) $this->db->fetchOne("SELECT COALESCE(SUM(ri.quantity),0) FROM mc_return_item ri JOIN mc_return_request rr ON rr.id=ri.return_id WHERE ri.order_item_id=? AND rr.status NOT IN ('rejected','cancelled')", [$id]);
            $available = max(0.0, $ordered - $alreadyRequested);
            if ($qty > $available + 0.0000001) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.return.application.returnrequestservice.kilkist_povernennia_perevyshchuie_dostupnyi_zalyshok'));
            if ($qty > 0) $valid[$id] = number_format($qty, 6, '.', '');
        }
        if ($valid===[]) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.return.application.returnrequestservice.oberit_khocha_b_odyn_tovar_dlia_povernennia'));

        return $this->db->transactional(function(Connection $db) use ($storeId,$customerId,$order,$valid,$reason,$note): string {
            $uuid=$this->ids->generate(); $now=gmdate('Y-m-d H:i:s.u');
            $db->insert('mc_return_request',['public_id'=>$uuid->toBinary(),'store_id'=>$storeId,'order_id'=>(int)$order['id'],'customer_id'=>$customerId,'status'=>'requested','reason_code'=>$reason,'customer_note'=>$note!==''?$note:null,'created_at'=>$now,'updated_at'=>$now]);
            $returnId=(int)$db->lastInsertId();
            foreach($valid as $orderItemId=>$qty){$db->insert('mc_return_item',['return_id'=>$returnId,'order_item_id'=>$orderItemId,'quantity'=>$qty,'reason_code'=>$reason]);}
            $db->insert('mc_return_event',['return_id'=>$returnId,'event_type'=>'requested','actor_type'=>'customer','actor_id'=>$customerId,'payload'=>json_encode(['reason'=>$reason],JSON_THROW_ON_ERROR),'created_at'=>$now]);
            return $uuid->toRfc4122();
        });
    }

    public function updateStatus(int $storeId, string $publicId, string $status, ?int $adminId, string $note='', string $resolution='', string $trackingNumber=''): void
    {
        if (!in_array($status,self::STATUSES,true)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.return.application.returnrequestservice.nekorektnyi_status_povernennia'));
        try{$binary=Uuid::fromString($publicId)->toBinary();}catch(\Throwable){throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.return.application.returnrequestservice.povernennia_ne_znaideno'));}
        $row=$this->db->fetchAssociative('SELECT id,status FROM mc_return_request WHERE public_id=? AND store_id=? LIMIT 1',[$binary,$storeId]);
        if(!is_array($row)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.return.application.returnrequestservice.povernennia_ne_znaideno'));

        $current=(string)$row['status'];
        if ($status !== $current && !in_array($status, self::TRANSITIONS[$current] ?? [], true)) {
            throw new \DomainException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.return.application.returnrequestservice.perekhid_statusu_s_s_nedostupnyi'), $current, $status));
        }

        $resolution=trim($resolution);
        if ($resolution!=='' && !in_array($resolution,self::RESOLUTIONS,true)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.return.application.returnrequestservice.nekorektne_rishennia_po_povernenniu'));
        if ($status==='resolved' && ($resolution==='' || $resolution==='none')) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.return.application.returnrequestservice.dlia_zavershenoho_povernennia_vkazhit_faktychne_rish'));

        $trackingNumber=mb_substr(trim(strip_tags($trackingNumber)),0,190);
        $now=gmdate('Y-m-d H:i:s.u');
        $fields=['status'=>$status,'updated_at'=>$now];
        $note=mb_substr(trim(strip_tags($note)),0,4000);
        if($note!=='')$fields['admin_note']=$note;
        if($resolution!=='')$fields['resolution']=$resolution;
        if($trackingNumber!=='')$fields['return_tracking_number']=$trackingNumber;
        if($status==='approved' && $current!=='approved')$fields['approved_at']=$now;
        if($status==='received' && $current!=='received')$fields['received_at']=$now;
        if($status==='resolved' && $current!=='resolved')$fields['resolved_at']=$now;

        $this->db->transactional(function(Connection $db)use($row,$fields,$status,$current,$adminId,$now,$note,$resolution,$trackingNumber):void{
            $db->update('mc_return_request',$fields,['id'=>(int)$row['id']]);
            $db->insert('mc_return_event',[
                'return_id'=>(int)$row['id'],
                'event_type'=>$status===$current?'updated':'status_changed',
                'actor_type'=>'admin',
                'actor_id'=>$adminId,
                'payload'=>json_encode(['from'=>$current,'to'=>$status,'note'=>$note!==''?$note:null,'resolution'=>$resolution!==''?$resolution:null,'tracking_number'=>$trackingNumber!==''?$trackingNumber:null],JSON_THROW_ON_ERROR),
                'created_at'=>$now,
            ]);
        });
    }

    /** @return array<string,mixed> */
    public function adminDetail(int $storeId, string $publicId): array
    {
        try{$binary=Uuid::fromString($publicId)->toBinary();}catch(\Throwable){throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.return.application.returnrequestservice.povernennia_ne_znaideno'));}
        $return=$this->db->fetchAssociative("SELECT rr.*,o.order_number,o.currency,o.total_minor,c.display_name customer_name,c.email customer_email FROM mc_return_request rr JOIN mc_sales_order o ON o.id=rr.order_id LEFT JOIN mc_customer c ON c.id=rr.customer_id WHERE rr.public_id=? AND rr.store_id=? LIMIT 1",[$binary,$storeId]);
        if(!is_array($return))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.return.application.returnrequestservice.povernennia_ne_znaideno'));
        $return['public_id']=$publicId;
        $return['allowed_statuses']=array_values(array_unique(array_merge([(string)$return['status']], self::TRANSITIONS[(string)$return['status']] ?? [])));
        $return['items']=$this->db->fetchAllAssociative("SELECT ri.id,ri.quantity,ri.reason_code,ri.condition_code,ri.resolution,ri.refund_amount_minor,ri.restock,oi.sku,oi.name,oi.unit_price_minor,oi.line_total_minor FROM mc_return_item ri JOIN mc_sales_order_item oi ON oi.id=ri.order_item_id WHERE ri.return_id=? ORDER BY ri.id",[(int)$return['id']]);
        $events=$this->db->fetchAllAssociative('SELECT event_type,actor_type,actor_id,payload,created_at FROM mc_return_event WHERE return_id=? ORDER BY id DESC',[(int)$return['id']]);
        foreach($events as &$event){try{$event['payload']=is_string($event['payload'])&&$event['payload']!==''?json_decode($event['payload'],true,512,JSON_THROW_ON_ERROR):[];}catch(\Throwable){$event['payload']=[];}}unset($event);
        $return['events']=$events;
        return $return;
    }

    /** @return list<array<string,mixed>> */
    public function forOrder(int $customerId,int $storeId,string $orderPublicId):array
    {
        try{$binary=Uuid::fromString($orderPublicId)->toBinary();}catch(\Throwable){return[];}
        $rows=$this->db->fetchAllAssociative('SELECT rr.public_id,rr.status,rr.reason_code,rr.resolution,rr.created_at,COUNT(ri.id) item_count FROM mc_return_request rr JOIN mc_sales_order o ON o.id=rr.order_id LEFT JOIN mc_return_item ri ON ri.return_id=rr.id WHERE o.public_id=? AND rr.customer_id=? AND rr.store_id=? GROUP BY rr.id ORDER BY rr.id DESC',[$binary,$customerId,$storeId]);
        foreach($rows as &$r){$r['public_id']=Uuid::fromBinary((string)$r['public_id'])->toRfc4122();$r['item_count']=(int)$r['item_count'];}unset($r);return $rows;
    }
}
