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
        $base=(int)$o['total_minor']; $lines=$db->fetchAllAssociative("SELECT i.line_total_minor,e.points_percent FROM mc_sales_order_item i LEFT JOIN mc_product_extra e ON e.product_id=i.product_id WHERE i.order_id=?",[$orderId]); $sum=0;$weighted=0; foreach($lines as $ln){$sum+=(int)$ln['line_total_minor'];$weighted+=(int)round((int)$ln['line_total_minor']*($ln['points_percent']===null?100:(int)$ln['points_percent'])/100);} if($sum>0 && $weighted!==$sum){$base=(int)round($base*$weighted/$sum);} $points=intdiv($base,100)*max(0,(int)$cfg['earn_points_per_major']); if($points<=0) return; $sid=(int)$o['store_id'];$cid=(int)$o['customer_id'];
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
    /** Manual bonus correction for a customer found by e-mail: plus gives points, minus takes them (never below zero). */
    public function adjust(int $storeId,string $email,int $points): void
    {
        if($points===0) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.rewards.adjust_zero'));
        $email=mb_strtolower(trim($email));
        $customer=$this->db->fetchOne("SELECT id FROM mc_customer WHERE email_normalized=? AND status='active' LIMIT 1",[$email]);
        if($customer===false) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.b2b.http.b2badmincontroller.pokuptsia_z_takym_email_ne_znaideno'));
        $this->db->transactional(function(Connection $db) use($storeId,$customer,$points):void{
            $now=$this->now();
            $db->executeStatement('INSERT IGNORE INTO mc_loyalty_account(store_id,customer_id,points_balance,lifetime_earned,lifetime_spent,updated_at) VALUES (?,?,0,0,0,?)',[$storeId,(int)$customer,$now]);
            $balance=(int)$db->fetchOne('SELECT points_balance FROM mc_loyalty_account WHERE store_id=? AND customer_id=? FOR UPDATE',[$storeId,(int)$customer]);
            $next=$balance+$points; if($next<0) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.rewards.adjust_below_zero'));
            $db->executeStatement('UPDATE mc_loyalty_account SET points_balance=?,lifetime_earned=lifetime_earned+?,updated_at=? WHERE store_id=? AND customer_id=?',[$next,max(0,$points),$now,$storeId,(int)$customer]);
            $db->insert('mc_loyalty_transaction',['store_id'=>$storeId,'customer_id'=>(int)$customer,'order_id'=>null,'tx_type'=>'adjust','points'=>$points,'balance_after'=>$next,'idempotency_key'=>'adjust:'.$customer.':'.bin2hex(random_bytes(6)),'created_at'=>$now]);
        });
    }

    /** @return array{accounts:list<array<string,mixed>>,transactions:list<array<string,mixed>>,total_points:int} */
    public function overview(int $storeId): array
    {
        return [
            'accounts'=>$this->db->fetchAllAssociative('SELECT c.display_name,c.email,a.points_balance,a.lifetime_earned,a.lifetime_spent,a.updated_at FROM mc_loyalty_account a JOIN mc_customer c ON c.id=a.customer_id WHERE a.store_id=? ORDER BY a.points_balance DESC LIMIT 50',[$storeId]),
            'transactions'=>$this->db->fetchAllAssociative('SELECT c.display_name,c.email,t.tx_type,t.points,t.balance_after,t.created_at FROM mc_loyalty_transaction t JOIN mc_customer c ON c.id=t.customer_id WHERE t.store_id=? ORDER BY t.id DESC LIMIT 50',[$storeId]),
            'total_points'=>(int)$this->db->fetchOne('SELECT COALESCE(SUM(points_balance),0) FROM mc_loyalty_account WHERE store_id=?',[$storeId]),
        ];
    }

    private function now(): string { return gmdate('Y-m-d H:i:s.u'); }
}
