<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketing\Application;

use Commerce\Core\Id\PublicIdFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

final readonly class NewsletterCampaignService
{
    public const FORMATS=['text','html','html_raw'];
    public function __construct(private Connection $db,private PublicIdFactory $ids,private MarketingSegmentService $segments,#[Autowire(service:'html_sanitizer.sanitizer.commerce.email_html')] private HtmlSanitizerInterface $emailHtml){}
    /** The body as it is stored: plain text stays text, HTML goes through the e-mail sanitizer (no scripts, forms, http links or tracking CSS). */
    public function cleanBody(string $body,string $format):string{return $format==='text'?trim($body):trim($this->emailHtml->sanitize($body));}
    /** @return array{id:int,recipients:int} */
    public function createAndEnqueue(int $storeId,string $subject,string $body,string $segment='all_subscribers',int $limit=5000,string $format='text',?string $sendAt=null,?string $locale=null):array
    {
        if(!in_array($format,self::FORMATS,true))$format='text';
        $subject=trim($subject);$body=$this->cleanBody($body,$format);$labels=$this->segments->labels();
        if(!isset($labels[$segment]))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.newslettercampaignservice.oberit_korektnyi_sehment'));
        if($subject===''||mb_strlen($subject)>255)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.newslettercampaignservice.vkazhit_temu_kampanii'));
        if($body===''||mb_strlen($body)>20000)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.newslettercampaignservice.vkazhit_tekst_kampanii'));
        $locale=$locale!==null&&preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D',$locale)===1?$locale:null;$sendAtDb=null;if($sendAt!==null&&trim($sendAt)!==''){try{$when=new DateTimeImmutable(trim($sendAt),new DateTimeZone('UTC'));}catch(\Throwable){throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.campaigns.bad_send_at'));}if($when->getTimestamp()>time()+60)$sendAtDb=$when->format('Y-m-d H:i:s.u');}$eligible=$this->segments->count($storeId,$segment,$locale);if($eligible<=0)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.newslettercampaignservice.u_vybranomu_sehmenti_nemaie_pidtverdzhenykh_pidpysny'));
        $now=$this->now();$this->db->insert('mc_marketing_campaign',['public_id'=>$this->ids->binary(),'store_id'=>$storeId,'subject'=>$subject,'body_text'=>$body,'body_format'=>$format,'segment_code'=>$segment,'status'=>'queued','recipient_count'=>0,'cursor_subscriber_id'=>0,'send_at'=>$sendAtDb,'locale'=>$locale,'created_at'=>$now,'enqueued_at'=>null]);
        return ['id'=>(int)$this->db->lastInsertId(),'recipients'=>min($eligible,max(1,min(5000,$limit)))];
    }
    private function now():string{return(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');}
}
