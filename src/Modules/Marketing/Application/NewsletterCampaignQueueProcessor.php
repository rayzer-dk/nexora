<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketing\Application;

use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class NewsletterCampaignQueueProcessor
{
    public function __construct(private Connection $db,private NotificationOutbox $outbox,private NewsletterTokenService $tokens,private MarketingSegmentService $segments,private string $publicBaseUrl) {}
    /** @return array{campaign_id:int|null,enqueued:int,completed:bool} */
    public function processOneBatch(int $limit = 250): array
    {
        $limit=max(1,min(500,$limit));$this->db->beginTransaction();
        try{$campaign=$this->db->fetchAssociative("SELECT id,store_id,subject,body_text,body_format,segment_code,locale,recipient_count,cursor_subscriber_id FROM mc_marketing_campaign WHERE status IN ('queued','enqueuing') AND (send_at IS NULL OR send_at<=UTC_TIMESTAMP(6)) ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED");
            if($campaign===false){$this->db->commit();return ['campaign_id'=>null,'enqueued'=>0,'completed'=>true];}
            $campaignId=(int)$campaign['id'];$this->db->update('mc_marketing_campaign',['status'=>'enqueuing'],['id'=>$campaignId]);
            $rows=$this->segments->recipients((int)$campaign['store_id'],(string)$campaign['segment_code'],$limit,(int)$campaign['cursor_subscriber_id'],($campaign['locale']??null)!==null?(string)$campaign['locale']:null);
            if($rows===[]){$this->db->update('mc_marketing_campaign',['status'=>'enqueued','enqueued_at'=>$this->now()],['id'=>$campaignId]);$this->db->commit();return ['campaign_id'=>$campaignId,'enqueued'=>0,'completed'=>true];}
            $lastId=(int)$campaign['cursor_subscriber_id'];foreach($rows as $row){$subscriberId=Uuid::fromBinary((string)$row['public_id'])->toRfc4122();$unsubscribe=rtrim($this->publicBaseUrl,'/').'/newsletter/unsubscribe/'.$subscriberId.'/'.$this->tokens->sign($subscriberId);$message=new NotificationMessage('marketing.campaign',(string)$campaign['subject'],(string)$campaign['body_text'],['unsubscribe_url'=>$unsubscribe,'body_html'=>(string)$campaign['body_format']!=='text','footer_text'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.newslettercampaignqueueprocessor.vy_otrymaly_tsei_lyst_tomu_shcho_pidtverdyly_markety')],(string)$campaign['body_format']==='html_raw'?'campaign_raw':'campaign');$this->outbox->enqueue(NotificationChannel::Email,$message,(string)$row['email'],null,'campaign:'.$campaignId.':'.$subscriberId);$lastId=(int)$row['id'];}
            $this->db->update('mc_marketing_campaign',['cursor_subscriber_id'=>$lastId,'recipient_count'=>(int)$campaign['recipient_count']+count($rows)],['id'=>$campaignId]);$this->db->commit();return ['campaign_id'=>$campaignId,'enqueued'=>count($rows),'completed'=>false];
        }catch(\Throwable $e){if($this->db->isTransactionActive())$this->db->rollBack();throw $e;}
    }
    private function now():string{return(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');}
}
