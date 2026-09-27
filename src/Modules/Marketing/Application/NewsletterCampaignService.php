<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketing\Application;

use Commerce\Core\Id\PublicIdFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

final readonly class NewsletterCampaignService
{
    public function __construct(private Connection $db,private PublicIdFactory $ids,private MarketingSegmentService $segments){}
    /** @return array{id:int,recipients:int} */
    public function createAndEnqueue(int $storeId,string $subject,string $body,string $segment='all_subscribers',int $limit=5000):array
    {
        $subject=trim($subject);$body=trim($body);$labels=$this->segments->labels();
        if(!isset($labels[$segment]))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.newslettercampaignservice.oberit_korektnyi_sehment'));
        if($subject===''||mb_strlen($subject)>255)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.newslettercampaignservice.vkazhit_temu_kampanii'));
        if($body===''||mb_strlen($body)>20000)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.newslettercampaignservice.vkazhit_tekst_kampanii'));
        $eligible=$this->segments->count($storeId,$segment);if($eligible<=0)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.newslettercampaignservice.u_vybranomu_sehmenti_nemaie_pidtverdzhenykh_pidpysny'));
        $now=$this->now();$this->db->insert('mc_marketing_campaign',['public_id'=>$this->ids->binary(),'store_id'=>$storeId,'subject'=>$subject,'body_text'=>$body,'segment_code'=>$segment,'status'=>'queued','recipient_count'=>0,'cursor_subscriber_id'=>0,'created_at'=>$now,'enqueued_at'=>null]);
        return ['id'=>(int)$this->db->lastInsertId(),'recipients'=>min($eligible,max(1,min(5000,$limit)))];
    }
    private function now():string{return(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');}
}
