<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketing\Provider;

use Commerce\Modules\Marketing\Contract\MarketingEventProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class TikTokEventsApiProvider implements MarketingEventProviderInterface
{
    public function __construct(private HttpClientInterface $http, private bool $isEnabled, private string $pixelCode, private string $accessToken, private string $endpoint) {}
    public function code(): string { return 'tiktok'; }
    public function consentScope(): string { return 'marketing'; }
    public function enabled(): bool { return $this->isEnabled && $this->pixelCode!=='' && $this->accessToken!==''; }
    public function send(array $event): array
    {
        $p=(array)($event['payload']??[]); $customer=(array)($p['customer']??[]); $user=[];
        if(!empty($customer['email'])) $user['email']=hash('sha256',strtolower(trim((string)$customer['email'])));
        if(!empty($customer['phone'])) $user['phone']=hash('sha256',(string)preg_replace('/\D+/','',(string)$customer['phone']));
        if(!empty($p['client_ip_address'])) $user['ip']=(string)$p['client_ip_address'];
        if(!empty($p['client_user_agent'])) $user['user_agent']=(string)$p['client_user_agent'];
        $properties=(array)($p['marketing_params']??[]); unset($properties['consent'],$properties['email'],$properties['phone']);
        $body=['event_source'=>'web','event_source_id'=>$this->pixelCode,'data'=>[array_filter([
            'event'=>$this->eventName((string)$event['event_name']),'event_time'=>time(),'event_id'=>(string)$event['event_id'],'page'=>['url'=>$p['page_url']??null],'user'=>$user,'properties'=>$properties,
        ],static fn($v)=>$v!==null)]];
        $r=$this->http->request('POST',$this->endpoint,['headers'=>['Access-Token'=>$this->accessToken],'json'=>$body,'timeout'=>10.0]);
        return ['status'=>$r->getStatusCode(),'body'=>mb_substr($r->getContent(false),0,1000)];
    }
    private function eventName(string $name): string { return match($name){'commerce.order.placed'=>'CompletePayment','commerce.order.completed'=>'CompletePayment','commerce.customer.registered'=>'CompleteRegistration',default=>'CustomEvent'}; }
}
