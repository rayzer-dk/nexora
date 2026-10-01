<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketing\Application;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Uid\Uuid;

final readonly class MarketingAttributionService
{
    private const SESSION='marketing.attribution';
    public function __construct(private Connection $db) {}

    public function capture(Request $request): void
    {
        if(!$request->hasSession() || !$request->isMethodSafe()) return;
        if(str_starts_with($request->getPathInfo(),'/admin') || str_starts_with($request->getPathInfo(),'/api')) return;
        // Only page views are touches. Pictures, scripts, captcha images and feeds must never start a session or set a cookie:
        // a cookie on a static-looking URL keeps it out of every shared cache and creates a session file per image request.
        foreach(['/media/','/assets/','/build/','/captcha/'] as $prefix){if(str_starts_with($request->getPathInfo(),$prefix)) return;}
        if(!str_contains((string)$request->headers->get('Accept',''),'text/html')) return;
        $q=$request->query;
        $hasCampaign=false; foreach(['utm_source','utm_medium','utm_campaign','utm_content','utm_term'] as $k){if(trim((string)$q->get($k))!==''){$hasCampaign=true;break;}}
        $session=$request->getSession(); $existing=$session->get(self::SESSION,[]); if(!is_array($existing))$existing=[];
        $touch=[
            'source'=>$this->clean((string)$q->get('utm_source')),
            'medium'=>$this->clean((string)$q->get('utm_medium')),
            'campaign'=>$this->clean((string)$q->get('utm_campaign')),
            'content'=>$this->clean((string)$q->get('utm_content')),
            'term'=>$this->clean((string)$q->get('utm_term')),
            'landing_url'=>$this->url($request->getUri()),
            'referrer_url'=>$this->url((string)$request->headers->get('referer','')),
            'captured_at'=>gmdate('Y-m-d H:i:s.u'),
        ];
        if(!isset($existing['first'])) $existing['first']=$touch;
        if($hasCampaign || !isset($existing['last'])) $existing['last']=$touch;
        $session->set(self::SESSION,$existing);
    }

    public function attachOrder(string $orderPublicId,SessionInterface $session): void
    {
        $data=$session->get(self::SESSION,[]); if(!is_array($data)||!isset($data['first'],$data['last'])) return;
        try{$binary=Uuid::fromString($orderPublicId)->toBinary();}catch(\Throwable){return;}
        $order=$this->db->fetchAssociative('SELECT id,store_id FROM mc_sales_order WHERE public_id=? LIMIT 1',[$binary]); if(!is_array($order))return;
        $first=is_array($data['first'])?$data['first']:[];$last=is_array($data['last'])?$data['last']:[];
        $this->db->executeStatement("INSERT INTO mc_order_attribution(order_id,store_id,first_source,first_medium,first_campaign,last_source,last_medium,last_campaign,last_content,last_term,landing_url,referrer_url,captured_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE last_source=VALUES(last_source),last_medium=VALUES(last_medium),last_campaign=VALUES(last_campaign),last_content=VALUES(last_content),last_term=VALUES(last_term)",[
            (int)$order['id'],(int)$order['store_id'],$first['source']??null,$first['medium']??null,$first['campaign']??null,$last['source']??null,$last['medium']??null,$last['campaign']??null,$last['content']??null,$last['term']??null,$first['landing_url']??null,$first['referrer_url']??null,
        ]);
    }
    private function clean(string $v):?string{$v=trim($v);return $v===''?null:mb_substr($v,0,190);}
    private function url(string $v):?string{$v=trim($v);if($v===''||mb_strlen($v)>1000)return null;return filter_var($v,FILTER_VALIDATE_URL)!==false?$v:null;}
}
