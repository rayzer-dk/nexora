<?php

declare(strict_types=1);
namespace Commerce\Modules\Rewards\Application;

use Doctrine\DBAL\Connection;

final readonly class LoyaltyService
{
    public function __construct(private Connection $db) {}

    public function account(int $storeId,int $customerId): array
    {
        $row=$this->db->fetchAssociative('SELECT * FROM mc_loyalty_account WHERE store_id=? AND customer_id=?',[$storeId,$customerId]);
        return is_array($row)?$row:['store_id'=>$storeId,'customer_id'=>$customerId,'points_balance'=>0,'lifetime_earned'=>0,'lifetime_spent'=>0];
    }
    public function transactions(int $storeId,int $customerId): array { return $this->db->fetchAllAssociative('SELECT tx_type,points,balance_after,created_at FROM mc_loyalty_transaction WHERE store_id=? AND customer_id=? ORDER BY id DESC LIMIT 100',[$storeId,$customerId]); }
    public function config(int $storeId): array { $r=$this->db->fetchAssociative('SELECT * FROM mc_loyalty_config WHERE store_id=?',[$storeId]); return is_array($r)?$r:['enabled'=>0,'earn_points_per_major'=>1,'redeem_minor_per_point'=>1,'min_redeem_points'=>100]; }

    /** @return array{points:int,amount_minor:int} */
    public function redeem(Connection $db,int $storeId,int $customerId,int $requestedPoints,int $maxMinor,int $orderId): array
    {
        if($requestedPoints<=0||$maxMinor<=0) return ['points'=>0,'amount_minor'=>0]; $cfg=$this->config($storeId); if(!(bool)$cfg['enabled']) return ['points'=>0,'amount_minor'=>0];
        $db->executeStatement('INSERT IGNORE INTO mc_loyalty_account(store_id,customer_id,points_balance,lifetime_earned,lifetime_spent,updated_at) VALUES (?,?,0,0,0,UTC_TIMESTAMP(6))',[$storeId,$customerId]);
        $a=$db->fetchAssociative('SELECT * FROM mc_loyalty_account WHERE store_id=? AND customer_id=? FOR UPDATE',[$storeId,$customerId]); if(!is_array($a)) return ['points'=>0,'amount_minor'=>0];
        $min=(int)$cfg['min_redeem_points']; if($requestedPoints<$min) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.rewards.application.loyaltyservice.minimalna_kilkist_baliv_dlia_spysannia').$min.'.');
        $per=max(1,(int)$cfg['redeem_minor_per_point']); $points=min($requestedPoints,(int)$a['points_balance'],intdiv($maxMinor,$per)); if($points<$min) return ['points'=>0,'amount_minor'=>0];
        $amount=$points*$per; $balance=(int)$a['points_balance']-$points; $now=$this->now();
        $db->update('mc_loyalty_account',['points_balance'=>$balance,'lifetime_spent'=>(int)$a['lifetime_spent']+$points,'updated_at'=>$now],['store_id'=>$storeId,'customer_id'=>$customerId]);
        $db->insert('mc_loyalty_transaction',['store_id'=>$storeId,'customer_id'=>$customerId,'order_id'=>$orderId,'tx_type'=>'redeem','points'=>-$points,'balance_after'=>$balance,'idempotency_key'=>'order:'.$orderId.':redeem','created_at'=>$now]);
        return ['points'=>$points,'amount_minor'=>$amount];
    }

    public function earnForPaidOrder(Connection $db,int $orderId): void
    {
        $o=$db->fetchAssociative('SELECT id,store_id,customer_id,total_minor FROM mc_sales_order WHERE id=? FOR UPDATE',[$orderId]); if(!is_array($o)||$o['customer_id']===null) return;
        $key='order:'.$orderId.':earn'; if($db->fetchOne('SELECT id FROM mc_loyalty_transaction WHERE idempotency_key=?',[$key])) return; $cfg=$this->config((int)$o['store_id']); if(!(bool)$cfg['enabled']) return;
        $points=intdiv((int)$o['total_minor'],100)*max(0,(int)$cfg['earn_points_per_major']); if($points<=0) return; $sid=(int)$o['store_id'];$cid=(int)$o['customer_id'];
        $db->executeStatement('INSERT IGNORE INTO mc_loyalty_account(store_id,customer_id,points_balance,lifetime_earned,lifetime_spent,updated_at) VALUES (?,?,0,0,0,UTC_TIMESTAMP(6))',[$sid,$cid]);
        $a=$db->fetchAssociative('SELECT * FROM mc_loyalty_account WHERE store_id=? AND customer_id=? FOR UPDATE',[$sid,$cid]); $balance=(int)$a['points_balance']+$points;$now=$this->now();
        $db->update('mc_loyalty_account',['points_balance'=>$balance,'lifetime_earned'=>(int)$a['lifetime_earned']+$points,'updated_at'=>$now],['store_id'=>$sid,'customer_id'=>$cid]);
        $db->insert('mc_loyalty_transaction',['store_id'=>$sid,'customer_id'=>$cid,'order_id'=>$orderId,'tx_type'=>'earn','points'=>$points,'balance_after'=>$balance,'idempotency_key'=>$key,'created_at'=>$now]);
    }

    public function restoreSpendForOrder(Connection $db,int $orderId,string $reason): void
    {
        $tx=$db->fetchAssociative("SELECT * FROM mc_loyalty_transaction WHERE order_id=? AND tx_type='redeem' LIMIT 1 FOR UPDATE",[$orderId]); if(!is_array($tx)) return; $key='order:'.$orderId.':restore'; if($db->fetchOne('SELECT id FROM mc_loyalty_transaction WHERE idempotency_key=?',[$key])) return;
        $sid=(int)$tx['store_id'];$cid=(int)$tx['customer_id'];$a=$db->fetchAssociative('SELECT * FROM mc_loyalty_account WHERE store_id=? AND customer_id=? FOR UPDATE',[$sid,$cid]); if(!is_array($a))return;$points=abs((int)$tx['points']);$balance=(int)$a['points_balance']+$points;$now=$this->now();
        $db->update('mc_loyalty_account',['points_balance'=>$balance,'lifetime_spent'=>max(0,(int)$a['lifetime_spent']-$points),'updated_at'=>$now],['store_id'=>$sid,'customer_id'=>$cid]);
        $db->insert('mc_loyalty_transaction',['store_id'=>$sid,'customer_id'=>$cid,'order_id'=>$orderId,'tx_type'=>'restore_'.$reason,'points'=>$points,'balance_after'=>$balance,'idempotency_key'=>$key,'created_at'=>$now]);
    }

    public function reverseEarnForOrder(Connection $db,int $orderId): void
    {
        $tx=$db->fetchAssociative("SELECT * FROM mc_loyalty_transaction WHERE order_id=? AND tx_type='earn' LIMIT 1 FOR UPDATE",[$orderId]); if(!is_array($tx)) return; $key='order:'.$orderId.':earn_reverse'; if($db->fetchOne('SELECT id FROM mc_loyalty_transaction WHERE idempotency_key=?',[$key])) return;
        $sid=(int)$tx['store_id'];$cid=(int)$tx['customer_id'];$a=$db->fetchAssociative('SELECT * FROM mc_loyalty_account WHERE store_id=? AND customer_id=? FOR UPDATE',[$sid,$cid]);if(!is_array($a))return;$points=max(0,(int)$tx['points']);$removed=min($points,(int)$a['points_balance']);$balance=(int)$a['points_balance']-$removed;$now=$this->now();
        $db->update('mc_loyalty_account',['points_balance'=>$balance,'lifetime_earned'=>max(0,(int)$a['lifetime_earned']-$points),'updated_at'=>$now],['store_id'=>$sid,'customer_id'=>$cid]);
        $db->insert('mc_loyalty_transaction',['store_id'=>$sid,'customer_id'=>$cid,'order_id'=>$orderId,'tx_type'=>'earn_reverse','points'=>-$removed,'balance_after'=>$balance,'idempotency_key'=>$key,'created_at'=>$now]);
    }
    private function now(): string { return gmdate('Y-m-d H:i:s.u'); }
}
