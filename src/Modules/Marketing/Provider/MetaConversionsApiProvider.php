<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketing\Provider;

use Commerce\Modules\Marketing\Contract\MarketingEventProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MetaConversionsApiProvider implements MarketingEventProviderInterface
{
    public function __construct(private HttpClientInterface $http, private bool $isEnabled, private string $pixelId, private string $accessToken, private string $apiBase, private string $apiVersion) {}
    public function code(): string { return 'meta'; }
    public function consentScope(): string { return 'marketing'; }
    public function enabled(): bool { return $this->isEnabled && $this->pixelId!=='' && $this->accessToken!==''; }
    public function send(array $event): array
    {
        $p=(array)($event['payload']??[]); $customer=(array)($p['customer']??[]);
        $user=[];
        if(!empty($customer['email'])) $user['em']=[hash('sha256',strtolower(trim((string)$customer['email'])))];
        if(!empty($customer['phone'])) $user['ph']=[hash('sha256',(string)preg_replace('/\D+/','',(string)$customer['phone']))];
        if(!empty($p['client_ip_address'])) $user['client_ip_address']=(string)$p['client_ip_address'];
        if(!empty($p['client_user_agent'])) $user['client_user_agent']=(string)$p['client_user_agent'];
        $custom=(array)($p['marketing_params']??[]); unset($custom['consent'],$custom['email'],$custom['phone']);
        $body=['data'=>[array_filter([
            'event_name'=>$this->eventName((string)$event['event_name']),'event_time'=>time(),'event_id'=>(string)$event['event_id'],
            'action_source'=>'website','event_source_url'=>$p['page_url']??null,'user_data'=>$user,'custom_data'=>$custom,
        ],static fn($v)=>$v!==null)]];
        $url=rtrim($this->apiBase,'/').'/'.$this->apiVersion.'/'.$this->pixelId.'/events';
        $r=$this->http->request('POST',$url,['query'=>['access_token'=>$this->accessToken],'json'=>$body,'timeout'=>10.0]);
        return ['status'=>$r->getStatusCode(),'body'=>mb_substr($r->getContent(false),0,1000)];
    }
    private function eventName(string $name): string { return match($name){'commerce.order.placed'=>'Purchase','commerce.order.completed'=>'Purchase','commerce.customer.registered'=>'CompleteRegistration',default=>'CustomEvent'}; }
}
