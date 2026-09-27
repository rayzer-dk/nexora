<?php

declare(strict_types=1);

namespace Commerce\Modules\Integration\Application;

use Commerce\Modules\GoogleCommerce\Application\GoogleMerchantSyncHandler;
use Commerce\Modules\Marketing\Application\MarketingEventDispatcher;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class IntegrationSyncWorker
{
    private const STALE_LOCK_SECONDS = 300;

    public function __construct(
        private Connection $db,
        private GoogleMerchantSyncHandler $google,
        private MarketingEventDispatcher $marketing,
        private IntegrationRetryPolicy $retryPolicy,
    ) {}

    /** @return array{processed:int,completed:int,retried:int,dead:int,recovered:int} */
    public function run(int $limit=50): array
    {
        $recovered = $this->recoverStaleJobs();
        $stats=['processed'=>0,'completed'=>0,'retried'=>0,'dead'=>0,'recovered'=>$recovered];
        $workerId=bin2hex(random_bytes(12));
        for($i=0;$i<max(1,min(500,$limit));$i++){
            $job=$this->claim($workerId); if($job===null) break; $stats['processed']++;
            try{
                match((string)$job['integration_code']){
                    'google_merchant'=>$this->google->handle($job),
                    'marketing'=>$this->marketing->handle($job),
                    default=>throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.fccf9f9e629d').$job['integration_code'])
                };
                $this->db->update('mc_integration_sync_queue',[
                    'status'=>'completed','last_error'=>null,'locked_at'=>null,'locked_by'=>null,
                    'updated_at'=>$this->now(),'completed_at'=>$this->now()
                ],['id'=>(int)$job['id'],'locked_by'=>$workerId]);
                $stats['completed']++;
            }catch(\Throwable $e){
                $attempts=(int)$job['attempts']; $decision=$this->retryPolicy->afterFailure($attempts); $dead=$decision['dead']; $delay=$decision['delay_seconds'];
                $this->db->update('mc_integration_sync_queue',[
                    'status'=>$dead?'dead':'pending','available_at'=>$dead?null:$this->after($delay),
                    'last_error'=>mb_substr(preg_replace('/[\r\n\t]+/',' ',$e->getMessage()) ?: 'integration failed',0,1000,'UTF-8'),
                    'locked_at'=>null,'locked_by'=>null,'updated_at'=>$this->now()
                ],['id'=>(int)$job['id'],'locked_by'=>$workerId]);
                $stats[$dead?'dead':'retried']++;
            }
        }
        return $stats;
    }

    private function recoverStaleJobs(): int
    {
        $stale=(new DateTimeImmutable('now',new DateTimeZone('UTC')))
            ->sub(new DateInterval('PT'.self::STALE_LOCK_SECONDS.'S'))->format('Y-m-d H:i:s.u');
        return $this->db->executeStatement(
            "UPDATE mc_integration_sync_queue SET status='pending', locked_at=NULL, locked_by=NULL, updated_at=? WHERE status='processing' AND (locked_at IS NULL OR locked_at<?)",
            [$this->now(),$stale]
        );
    }

    /** @return array<string,mixed>|null */
    private function claim(string $workerId): ?array
    {
        return $this->db->transactional(function(Connection $db) use($workerId): ?array{
            $row=$db->fetchAssociative("SELECT * FROM mc_integration_sync_queue WHERE status='pending' AND (available_at IS NULL OR available_at<=NOW(6)) ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED");
            if(!is_array($row)) return null;
            $attempts=(int)$row['attempts']+1;
            $db->update('mc_integration_sync_queue',[
                'status'=>'processing','attempts'=>$attempts,'locked_at'=>$this->now(),'locked_by'=>$workerId,'updated_at'=>$this->now()
            ],['id'=>(int)$row['id'],'status'=>'pending']);
            $row['attempts']=$attempts; $row['locked_by']=$workerId; return $row;
        });
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private function after(int $seconds): string
    {
        return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->add(new DateInterval('PT'.max(1,$seconds).'S'))->format('Y-m-d H:i:s.u');
    }
}
