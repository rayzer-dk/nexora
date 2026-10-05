<?php

declare(strict_types=1);
namespace Commerce\Modules\Rewards\Application;

use Commerce\Core\Id\PublicIdFactory;
use Doctrine\DBAL\Connection;

final readonly class GiftCardService
{
    public function __construct(private Connection $db, private PublicIdFactory $ids) {}

    /** @return array{code:string,last4:string,amount_minor:int,currency:string} */
    public function issue(int $storeId,int $amountMinor,string $currency,?int $customerId=null,?string $expiresAt=null): array
    {
        if($amountMinor<100) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.rewards.application.giftcardservice.suma_podarunkovoi_kartky_zanadto_mala'));
        $currency=strtoupper(trim($currency)); if(preg_match('/^[A-Z]{3}$/D',$currency)!==1) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.importexport.application.catalogcsvservice.nekorektna_valiuta'));
        $code='GC-'.strtoupper(bin2hex(random_bytes(8))); $hash=$this->hash($code); $last4=substr(str_replace('-','',$code),-4); $now=$this->now();
        $this->db->insert('mc_gift_card',['public_id'=>$this->ids->binary(),'store_id'=>$storeId,'customer_id'=>$customerId,'code_hash'=>$hash,'code_last4'=>$last4,'currency'=>$currency,'initial_minor'=>$amountMinor,'balance_minor'=>$amountMinor,'status'=>'active','expires_at'=>$expiresAt,'created_at'=>$now,'updated_at'=>$now]);
        $id=(int)$this->db->lastInsertId();
        $this->db->insert('mc_gift_card_transaction',['gift_card_id'=>$id,'order_id'=>null,'tx_type'=>'issue','amount_minor'=>$amountMinor,'balance_after_minor'=>$amountMinor,'idempotency_key'=>'issue:'.$id,'created_at'=>$now]);
        return ['code'=>$code,'last4'=>$last4,'amount_minor'=>$amountMinor,'currency'=>$currency];
    }

    /** @return array{amount_minor:int,last4:string}|null */
    public function preview(int $storeId,string $code,string $currency,int $maxMinor): ?array
    {
        if(trim($code)==='') return null; $row=$this->db->fetchAssociative("SELECT balance_minor,code_last4,currency,status,expires_at FROM mc_gift_card WHERE store_id=? AND code_hash=? LIMIT 1",[$storeId,$this->hash($code)]);
        if(!is_array($row)||$row['status']!=='active'||strtoupper((string)$row['currency'])!==strtoupper($currency)) return null;
        if($row['expires_at']!==null && strtotime((string)$row['expires_at'])<time()) return null;
        $amount=min(max(0,$maxMinor),(int)$row['balance_minor']); return $amount>0?['amount_minor'=>$amount,'last4'=>(string)$row['code_last4']]:null;
    }

    /** @return array{amount_minor:int,last4:string}|null */
    public function redeem(Connection $db,int $storeId,string $code,string $currency,int $maxMinor,int $orderId): ?array
    {
        if(trim($code)===''||$maxMinor<=0) return null;
        $row=$db->fetchAssociative("SELECT * FROM mc_gift_card WHERE store_id=? AND code_hash=? LIMIT 1 FOR UPDATE",[$storeId,$this->hash($code)]);
        if(!is_array($row)||$row['status']!=='active') throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.rewards.application.giftcardservice.podarunkova_kartka_nediisna_abo_neaktyvna'));
        if(strtoupper((string)$row['currency'])!==strtoupper($currency)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.rewards.application.giftcardservice.valiuta_podarunkovoi_kartky_ne_vidpovidaie_valiuti_z'));
        if($row['expires_at']!==null && strtotime((string)$row['expires_at'])<time()) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.rewards.application.giftcardservice.strok_dii_podarunkovoi_kartky_zavershyvsia'));
        $amount=min($maxMinor,(int)$row['balance_minor']); if($amount<=0) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.rewards.application.giftcardservice.na_podarunkovii_karttsi_nemaie_dostupnoho_zalyshku'));
        $balance=(int)$row['balance_minor']-$amount; $now=$this->now();
        $db->update('mc_gift_card',['balance_minor'=>$balance,'status'=>$balance===0?'exhausted':'active','updated_at'=>$now],['id'=>(int)$row['id']]);
        $db->insert('mc_gift_card_transaction',['gift_card_id'=>(int)$row['id'],'order_id'=>$orderId,'tx_type'=>'redeem','amount_minor'=>-$amount,'balance_after_minor'=>$balance,'idempotency_key'=>'order:'.$orderId.':redeem','created_at'=>$now]);
        return ['amount_minor'=>$amount,'last4'=>(string)$row['code_last4']];
    }

    public function restoreForOrder(Connection $db,int $orderId,string $reason): void
    {
        $tx=$db->fetchAssociative("SELECT t.*,g.balance_minor FROM mc_gift_card_transaction t JOIN mc_gift_card g ON g.id=t.gift_card_id WHERE t.order_id=? AND t.tx_type='redeem' LIMIT 1 FOR UPDATE",[$orderId]);
        if(!is_array($tx)) return; $key='order:'.$orderId.':restore'; if($db->fetchOne('SELECT id FROM mc_gift_card_transaction WHERE idempotency_key=?',[$key])) return;
        $amount=abs((int)$tx['amount_minor']); $balance=(int)$tx['balance_minor']+$amount; $now=$this->now();
        $db->update('mc_gift_card',['balance_minor'=>$balance,'status'=>'active','updated_at'=>$now],['id'=>(int)$tx['gift_card_id']]);
        $db->insert('mc_gift_card_transaction',['gift_card_id'=>(int)$tx['gift_card_id'],'order_id'=>$orderId,'tx_type'=>'restore_'.$reason,'amount_minor'=>$amount,'balance_after_minor'=>$balance,'idempotency_key'=>$key,'created_at'=>$now]);
    }

    /** Blocks a card (it cannot be spent) or makes it usable again; a card with no balance stays exhausted. */
    public function setStatus(int $storeId,int $cardId,string $status): void
    {
        if(!in_array($status,['active','blocked'],true)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.rewards.bad_status'));
        $row=$this->db->fetchAssociative('SELECT balance_minor FROM mc_gift_card WHERE id=? AND store_id=?',[$cardId,$storeId]);
        if(!is_array($row)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.rewards.card_not_found'));
        if($status==='active' && (int)$row['balance_minor']===0) $status='exhausted';
        $this->db->update('mc_gift_card',['status'=>$status,'updated_at'=>$this->now()],['id'=>$cardId,'store_id'=>$storeId]);
    }

    /** Manual correction of the balance (plus or minus); the balance never goes below zero. */
    public function adjust(int $storeId,int $cardId,int $deltaMinor): void
    {
        if($deltaMinor===0) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.rewards.adjust_zero'));
        $this->db->transactional(function(Connection $db) use($storeId,$cardId,$deltaMinor):void{
            $row=$db->fetchAssociative('SELECT balance_minor,status FROM mc_gift_card WHERE id=? AND store_id=? FOR UPDATE',[$cardId,$storeId]);
            if(!is_array($row)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.rewards.card_not_found'));
            $balance=(int)$row['balance_minor']+$deltaMinor;
            if($balance<0) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.rewards.adjust_below_zero'));
            $status=(string)$row['status']; if($status==='exhausted'&&$balance>0)$status='active'; if($status==='active'&&$balance===0)$status='exhausted';
            $now=$this->now();
            $db->update('mc_gift_card',['balance_minor'=>$balance,'status'=>$status,'updated_at'=>$now],['id'=>$cardId]);
            $db->insert('mc_gift_card_transaction',['gift_card_id'=>$cardId,'order_id'=>null,'tx_type'=>'adjust','amount_minor'=>$deltaMinor,'balance_after_minor'=>$balance,'idempotency_key'=>'adjust:'.$cardId.':'.bin2hex(random_bytes(6)),'created_at'=>$now]);
        });
    }

    public function setExpiry(int $storeId,int $cardId,?string $expiresAt): void
    {
        $value=null;
        if($expiresAt!==null&&trim($expiresAt)!==''){try{$value=(new \DateTimeImmutable(trim($expiresAt),new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');}catch(\Throwable){throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.rewards.bad_date'));}}
        $this->db->update('mc_gift_card',['expires_at'=>$value,'updated_at'=>$this->now()],['id'=>$cardId,'store_id'=>$storeId]);
    }

    /** @param list<int> $cardIds @return array<int,list<array<string,mixed>>> history of the cards, newest first */
    public function history(array $cardIds): array
    {
        if($cardIds===[]) return [];
        $in=implode(',',array_map('intval',$cardIds)); $out=[];
        foreach($this->db->fetchAllAssociative("SELECT t.gift_card_id,t.tx_type,t.amount_minor,t.balance_after_minor,t.created_at,o.order_number FROM mc_gift_card_transaction t LEFT JOIN mc_sales_order o ON o.id=t.order_id WHERE t.gift_card_id IN ($in) ORDER BY t.id DESC LIMIT 2000") as $r){$out[(int)$r['gift_card_id']][]=$r;}
        return $out;
    }

    public function list(int $storeId): array { return $this->db->fetchAllAssociative("SELECT id,code_last4,currency,initial_minor,balance_minor,status,expires_at,created_at,(SELECT COALESCE(SUM(-t.amount_minor),0) FROM mc_gift_card_transaction t WHERE t.gift_card_id=mc_gift_card.id AND t.tx_type='redeem') spent_minor FROM mc_gift_card WHERE store_id=? ORDER BY id DESC LIMIT 200",[$storeId]); }
    private function hash(string $code): string { return hash('sha256',strtoupper(preg_replace('/\s+/','',trim($code))??'')); }
    private function now(): string { return gmdate('Y-m-d H:i:s.u'); }
}
