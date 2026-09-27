<?php

declare(strict_types=1);

namespace Commerce\Modules\Api\Webhook;

use Commerce\Core\Security\OutboundUrlPolicy;
use Commerce\Core\Security\SecretVault;
use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ApiWebhookDeliveryWorker
{
    private const MAX_ATTEMPTS=8;
    private const STALE_LOCK_SECONDS=300;
    public function __construct(private Connection $db,private HttpClientInterface $http,private SecretVault $vault,private OutboundUrlPolicy $urls){}

    /** @return array{claimed:int,delivered:int,retried:int,dead:int} */
    public function run(int $limit=100):array
    {
        $limit=max(1,min(500,$limit));$now=$this->now();$stale=(new DateTimeImmutable('-'.self::STALE_LOCK_SECONDS.' seconds'))->format('Y-m-d H:i:s.u');
        $this->db->executeStatement("UPDATE mc_webhook_delivery SET status='retry',locked_at=NULL,lock_token=NULL WHERE status='processing' AND locked_at IS NOT NULL AND locked_at<?",[$stale]);
        $ids=$this->db->fetchFirstColumn("SELECT id FROM mc_webhook_delivery WHERE status IN ('pending','retry') AND next_attempt_at<=? ORDER BY id ASC LIMIT $limit",[$now]);
        $stats=['claimed'=>0,'delivered'=>0,'retried'=>0,'dead'=>0];
        foreach($ids as $id){$lock=random_bytes(16);$claimed=$this->db->executeStatement("UPDATE mc_webhook_delivery SET status='processing',locked_at=?,lock_token=? WHERE id=? AND status IN ('pending','retry')",[$now,$lock,(int)$id]);if($claimed!==1)continue;$stats['claimed']++;$outcome=$this->deliver((int)$id,$lock);$stats[$outcome]++;}
        return $stats;
    }

    private function deliver(int $id,string $lock):string
    {
        $row=$this->db->fetchAssociative('SELECT * FROM mc_webhook_delivery WHERE id=? AND lock_token=? LIMIT 1',[$id,$lock]);
        if(!is_array($row))return 'dead';
        try{$subscriptionBinary=Uuid::fromString((string)$row['subscription_key'])->toBinary();}catch(\Throwable){return $this->finish($id,$lock,'dead',null,'subscription key invalid',(int)$row['attempts']+1);}
        $subscriptionRow=$this->db->fetchAssociative('SELECT public_id,target_url,secret_cipher,status FROM mc_webhook_subscription WHERE store_id=? AND public_id=? LIMIT 1',[(int)$row['store_id'],$subscriptionBinary]);
        if(!is_array($subscriptionRow)||(string)$subscriptionRow['status']!=='active'){return $this->finish($id,$lock,'dead',null,'subscription disabled',(int)$row['attempts']+1);}
        $row['subscription_public_id']=$subscriptionRow['public_id'];$row['target_url']=$subscriptionRow['target_url'];$row['secret_cipher']=$subscriptionRow['secret_cipher'];
        $target=(string)$row['target_url']; if(!hash_equals(bin2hex((string)$row['target_url_hash']),hash('sha256',$target)))return $this->finish($id,$lock,'dead',null,'target changed',(int)$row['attempts']+1);
        try{
            $this->urls->assertPublicHttps($target,false);
            $subscription=Uuid::fromBinary((string)$row['subscription_public_id'])->toRfc4122();$secret=$this->vault->decrypt((string)$row['secret_cipher'],'api-webhook:'.$subscription);
            $payload=(string)$row['payload'];$timestamp=(string)time();$signature=hash_hmac('sha256',$timestamp.'.'.$payload,$secret);
            $response=$this->http->request('POST',$target,['headers'=>['Content-Type'=>'application/json','User-Agent'=>'Nexora-Commerce-Webhook/1','X-Nexora-Event'=>(string)$row['event_type'],'X-Nexora-Delivery'=>Uuid::fromBinary((string)$row['public_id'])->toRfc4122(),'X-Nexora-Timestamp'=>$timestamp,'X-Nexora-Signature'=>'sha256='.$signature],'body'=>$payload,'max_redirects'=>0,'timeout'=>10]);
            $status=$response->getStatusCode(); if($status>=200&&$status<300){$this->db->executeStatement('UPDATE mc_webhook_subscription SET last_delivery_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE public_id=?',[$row['subscription_public_id']]);return $this->finish($id,$lock,'delivered',$status,null,(int)$row['attempts']+1);}
            return $this->retryOrDead($row,$lock,$status,'http_status_'.$status);
        }catch(\Throwable $e){return $this->retryOrDead($row,$lock,null,$e::class);}
    }

    /** @param array<string,mixed> $row */
    private function retryOrDead(array $row,string $lock,?int $status,string $error):string
    {
        $attempts=(int)$row['attempts']+1;if($attempts>=self::MAX_ATTEMPTS)return $this->finish((int)$row['id'],$lock,'dead',$status,$error,$attempts);
        $seconds=min(3600,15*(2**max(0,$attempts-1)));$next=(new DateTimeImmutable())->add(new DateInterval('PT'.$seconds.'S'))->format('Y-m-d H:i:s.u');
        $this->db->executeStatement("UPDATE mc_webhook_delivery SET status='retry',attempts=?,next_attempt_at=?,response_status=?,last_error=?,locked_at=NULL,lock_token=NULL WHERE id=? AND lock_token=?",[$attempts,$next,$status,mb_substr($error,0,1000,'UTF-8'),(int)$row['id'],$lock]);return 'retried';
    }
    private function finish(int $id,string $lock,string $status,?int $httpStatus,?string $error,int $attempts=1):string{$this->db->executeStatement('UPDATE mc_webhook_delivery SET status=?,attempts=?,response_status=?,last_error=?,completed_at=UTC_TIMESTAMP(6),locked_at=NULL,lock_token=NULL WHERE id=? AND lock_token=?',[$status,$attempts,$httpStatus,$error,$id,$lock]);return $status==='delivered'?'delivered':'dead';}
    private function now():string{return gmdate('Y-m-d H:i:s.u');}
}
