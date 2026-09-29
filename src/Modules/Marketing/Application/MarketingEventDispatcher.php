<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketing\Application;

use Commerce\Modules\Marketing\Contract\MarketingEventProviderInterface;
use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class MarketingEventDispatcher
{
    /** @param iterable<MarketingEventProviderInterface> $providers */
    public function __construct(private Connection $db, private MarketingConsentGate $consent, private iterable $providers) {}

    /** @param array<string,mixed> $job */
    public function handle(array $job): void
    {
        $event=json_decode((string)$job['payload'],true,512,JSON_THROW_ON_ERROR);
        $failures=[];
        foreach($this->providers as $provider){
            if(!$provider->enabled()) continue;
            $storeId=isset($job['store_id'])?(int)$job['store_id']:null;
            if($this->alreadySent($provider->code(),(string)$event['event_id'],$storeId)) continue;
            if(!$this->consent->allows($event,$provider->consentScope())){
                $this->record($provider,(string)$event['event_id'],(string)$event['event_name'],(string)$job['aggregate_type'],(string)$job['aggregate_id'],$storeId,'skipped_no_consent',null,null);
                continue;
            }
            try{
                $result=$provider->send($event);
                if($result['status']<200||$result['status']>=300){throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.c4c49559170d').$result['status'].': '.$result['body']);}
                $this->record($provider,(string)$event['event_id'],(string)$event['event_name'],(string)$job['aggregate_type'],(string)$job['aggregate_id'],$storeId,'sent',$result['status'],null);
            }catch(\Throwable $e){
                $this->record($provider,(string)$event['event_id'],(string)$event['event_name'],(string)$job['aggregate_type'],(string)$job['aggregate_id'],$storeId,'failed',null,mb_substr($e->getMessage(),0,1000));
                $failures[]=$provider->code().': '.$e->getMessage();
            }
        }
        if($failures!==[]){throw new RuntimeException(implode(' | ',$failures));}
    }
    private function alreadySent(string $provider,string $eventId,?int $storeId): bool { return (int)$this->db->fetchOne("SELECT COUNT(*) FROM mc_marketing_delivery WHERE provider=? AND event_id=? AND store_id <=> ? AND status IN ('sent','skipped_no_consent')",[$provider,$eventId,$storeId])>0; }
    private function record(MarketingEventProviderInterface $provider,string $eventId,string $eventName,string $type,string $id,?int $storeId,string $status,?int $response,?string $error): void
    {
        $now=gmdate('Y-m-d H:i:s.u');
        $existing=$this->db->fetchAssociative('SELECT id,attempt_count FROM mc_marketing_delivery WHERE provider=? AND event_id=? AND store_id <=> ?',[$provider->code(),$eventId,$storeId]);
        $data=['store_id'=>$storeId,'event_name'=>$eventName,'aggregate_type'=>$type,'aggregate_id'=>$id,'consent_scope'=>$provider->consentScope(),'status'=>$status,'response_code'=>$response,'last_error'=>$error,'sent_at'=>$status==='sent'?$now:null,'updated_at'=>$now];
        if(is_array($existing)){$data['attempt_count']=(int)$existing['attempt_count']+1;$this->db->update('mc_marketing_delivery',$data,['id'=>(int)$existing['id']]);}
        else{$this->db->insert('mc_marketing_delivery',array_merge(['provider'=>$provider->code(),'event_id'=>$eventId,'attempt_count'=>1,'created_at'=>$now],$data));}
    }
}
