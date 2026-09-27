<?php

declare(strict_types=1);

namespace Commerce\Modules\OrderDocument\Application;

use Commerce\Core\Id\PublicIdFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class OrderDocumentService
{
    public function __construct(private Connection $db, private PublicIdFactory $ids) {}

    public function listForOrder(int $storeId, string $orderPublicId): array
    {
        $orderId=$this->orderId($storeId,$orderPublicId);
        $rows=$this->db->fetchAllAssociative('SELECT public_id,document_type,document_number,issued_at,created_by FROM mc_order_document WHERE store_id=? AND order_id=? ORDER BY id',[$storeId,$orderId]);
        foreach($rows as &$row){$row['public_id']=Uuid::fromBinary((string)$row['public_id'])->toRfc4122();} unset($row);
        return $rows;
    }

    public function issueInvoice(int $storeId,string $orderPublicId,string $actor):array{return $this->issue($storeId,$orderPublicId,'invoice',null,$actor);}
    public function issuePackingSlip(int $storeId,string $orderPublicId,string $actor):array{return $this->issue($storeId,$orderPublicId,'packing_slip',null,$actor);}
    public function issueCreditNote(int $storeId,string $orderPublicId,int $refundId,string $actor):array{return $this->issue($storeId,$orderPublicId,'credit_note',$refundId,$actor);}

    public function documentForStore(int $storeId,string $publicId):array
    {
        $row=$this->db->fetchAssociative('SELECT d.*,o.order_number FROM mc_order_document d JOIN mc_sales_order o ON o.id=d.order_id WHERE d.public_id=? AND d.store_id=? LIMIT 1',[$this->uuid($publicId),$storeId]);
        if(!is_array($row))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.application.orderdocumentservice.dokument_ne_znaideno')); return $this->hydrate($row);
    }

    public function documentForCustomer(int $customerId,int $storeId,string $publicId):array
    {
        $row=$this->db->fetchAssociative("SELECT d.*,o.order_number FROM mc_order_document d JOIN mc_sales_order o ON o.id=d.order_id WHERE d.public_id=? AND d.store_id=? AND o.customer_id=? AND d.document_type IN ('invoice','credit_note') LIMIT 1",[$this->uuid($publicId),$storeId,$customerId]);
        if(!is_array($row))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.application.orderdocumentservice.dokument_ne_znaideno')); return $this->hydrate($row);
    }

    private function issue(int $storeId,string $orderPublicId,string $type,?int $refundId,string $actor):array
    {
        if(!in_array($type,['invoice','packing_slip','credit_note'],true))throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3c6c8bcfc666'));
        return $this->db->transactional(function(Connection $db)use($storeId,$orderPublicId,$type,$refundId,$actor):array{
            $order=$db->fetchAssociative('SELECT * FROM mc_sales_order WHERE public_id=? AND store_id=? LIMIT 1 FOR UPDATE',[$this->uuid($orderPublicId),$storeId]);
            if(!is_array($order))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.zamovlennia_ne_znaideno')); if((string)$order['status']==='cancelled')throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.application.orderdocumentservice.dlia_skasovanoho_zamovlennia_dokument_ne_vypuskaiets'));
            $refund=null;
            if($type==='credit_note'){
                if(($refundId??0)<=0)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.application.orderdocumentservice.oberit_uspishne_povernennia_koshtiv'));
                $refund=$db->fetchAssociative('SELECT r.* FROM mc_payment_refund r JOIN mc_payment p ON p.id=r.payment_id WHERE r.id=? AND p.order_id=? LIMIT 1 FOR UPDATE',[$refundId,(int)$order['id']]);
                if(!is_array($refund)||(string)$refund['status']!=='succeeded')throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.application.orderdocumentservice.credit_note_dostupnyi_lyshe_dlia_uspishnoho_povernen'));
            }
            $issueKey=$type==='credit_note'?'credit:refund:'.(int)$refund['id']:$type.':order:'.(int)$order['id'];
            $existing=$db->fetchAssociative('SELECT d.*,o.order_number FROM mc_order_document d JOIN mc_sales_order o ON o.id=d.order_id WHERE d.store_id=? AND d.issue_key=? LIMIT 1',[$storeId,$issueKey]);
            if(is_array($existing))return $this->hydrate($existing);
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC')); $year=(int)$now->format('Y'); $seq=$this->nextSequence($db,$storeId,$type,$year,$now); $prefix=['invoice'=>'INV','packing_slip'=>'PK','credit_note'=>'CN'][$type]; $number=sprintf('%s-%04d-%06d',$prefix,$year,$seq);
            $snapshot=$this->snapshot($db,$order,$type,$refund); $id=$this->ids->generate(); $stamp=$now->format('Y-m-d H:i:s.u');
            $db->insert('mc_order_document',['public_id'=>$id->toBinary(),'store_id'=>$storeId,'order_id'=>(int)$order['id'],'payment_refund_id'=>$refund? (int)$refund['id']:null,'issue_key'=>$issueKey,'document_type'=>$type,'document_number'=>$number,'sequence_year'=>$year,'sequence_number'=>$seq,'locale'=>(string)($order['locale']?:'uk-UA'),'currency'=>(string)$order['currency'],'snapshot_json'=>json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'issued_at'=>$stamp,'created_by'=>mb_substr(strip_tags(trim($actor)),0,190)]);
            return ['public_id'=>$id->toRfc4122(),'order_number'=>(string)$order['order_number'],'document_type'=>$type,'document_number'=>$number,'issued_at'=>$stamp,'created_by'=>$actor,'snapshot'=>$snapshot];
        });
    }

    private function nextSequence(Connection $db,int $storeId,string $type,int $year,DateTimeImmutable $now):int
    {
        $db->executeStatement('INSERT IGNORE INTO mc_order_document_sequence (store_id,document_type,sequence_year,next_number,updated_at) VALUES (?,?,?,?,?)',[$storeId,$type,$year,1,$now->format('Y-m-d H:i:s.u')]);
        $row=$db->fetchAssociative('SELECT next_number FROM mc_order_document_sequence WHERE store_id=? AND document_type=? AND sequence_year=? FOR UPDATE',[$storeId,$type,$year]);
        if(!is_array($row))throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.application.orderdocumentservice.ne_vdalosia_otrymaty_poslidovnist_nomera_dokumenta'));
        $n=max(1,(int)$row['next_number']); $db->update('mc_order_document_sequence',['next_number'=>$n+1,'updated_at'=>$now->format('Y-m-d H:i:s.u')],['store_id'=>$storeId,'document_type'=>$type,'sequence_year'=>$year]); return $n;
    }

    private function snapshot(Connection $db,array $order,string $type,?array $refund):array
    {
        $seller=$db->fetchAssociative('SELECT s.name,p.legal_name,p.registration_number,p.tax_number,p.vat_number,p.country_code,p.registration_address,p.iban,p.bank_name,p.email,p.phone FROM mc_store s LEFT JOIN mc_store_profile p ON p.store_id=s.id WHERE s.id=?',[(int)$order['store_id']])?:[];
        $items=$db->fetchAllAssociative('SELECT sku,name,quantity,unit_price_minor,line_total_minor,tax_minor FROM mc_sales_order_item WHERE order_id=? ORDER BY id',[(int)$order['id']]);
        $fulfillment=$db->fetchAssociative('SELECT provider_code,service_type,status,tracking_number,destination_snapshot FROM mc_fulfillment WHERE order_id=? ORDER BY id DESC LIMIT 1',[(int)$order['id']])?:[];
        if(isset($fulfillment['destination_snapshot'])){try{$fulfillment['destination']=json_decode((string)$fulfillment['destination_snapshot'],true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){$fulfillment['destination']=[];}unset($fulfillment['destination_snapshot']);}
        return ['schema_version'=>1,'type'=>$type,'seller'=>$seller,'buyer'=>['name'=>(string)($order['customer_name']??''),'email'=>(string)($order['customer_email']??''),'phone'=>(string)($order['customer_phone']??'')],'order'=>['number'=>(string)$order['order_number'],'created_at'=>(string)$order['created_at'],'currency'=>(string)$order['currency'],'subtotal_minor'=>(int)$order['subtotal_minor'],'discount_minor'=>(int)$order['discount_minor'],'shipping_minor'=>(int)$order['shipping_minor'],'tax_minor'=>(int)$order['tax_minor'],'total_minor'=>(int)$order['total_minor']],'items'=>$items,'fulfillment'=>$fulfillment,'refund'=>$refund?['amount_minor'=>(int)$refund['amount_minor'],'created_at'=>(string)$refund['created_at']]:null];
    }

    private function hydrate(array $row):array
    {try{$snap=json_decode((string)$row['snapshot_json'],true,128,JSON_THROW_ON_ERROR);}catch(\Throwable){$snap=[];}return ['public_id'=>Uuid::fromBinary((string)$row['public_id'])->toRfc4122(),'order_number'=>(string)($row['order_number']??''),'document_type'=>(string)$row['document_type'],'document_number'=>(string)$row['document_number'],'issued_at'=>(string)$row['issued_at'],'created_by'=>$row['created_by']??null,'snapshot'=>is_array($snap)?$snap:[]];}
    private function orderId(int $storeId,string $publicId):int{$id=$this->db->fetchOne('SELECT id FROM mc_sales_order WHERE public_id=? AND store_id=?',[$this->uuid($publicId),$storeId]);if($id===false)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.zamovlennia_ne_znaideno'));return(int)$id;}
    private function uuid(string $id):string{try{return Uuid::fromString($id)->toBinary();}catch(\Throwable){throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.application.orderdocumentservice.nekorektnyi_identyfikator'));}}
}
