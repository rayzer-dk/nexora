<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketing\Provider;

use Commerce\Modules\Marketing\Contract\MarketingEventProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class Ga4MeasurementProtocolProvider implements MarketingEventProviderInterface
{
    public function __construct(private HttpClientInterface $http, private bool $isEnabled, private string $measurementId, private string $apiSecret, private string $endpoint) {}
    public function code(): string { return 'ga4'; }
    public function consentScope(): string { return 'analytics'; }
    public function enabled(): bool { return $this->isEnabled && $this->measurementId !== '' && $this->apiSecret !== ''; }

    public function send(array $event): array
    {
        $payload=(array)($event['payload'] ?? []);
        $clientId=(string)($payload['client_id'] ?? $this->deterministicClientId((string)$event['event_id']));
        $name=$this->eventName((string)$event['event_name']);
        $params=$this->cleanParams((array)($payload['marketing_params'] ?? $payload));
        $params['event_id']=(string)$event['event_id'];
        $response=$this->http->request('POST',$this->endpoint,[
            'query'=>['measurement_id'=>$this->measurementId,'api_secret'=>$this->apiSecret],
            'json'=>['client_id'=>$clientId,'user_id'=>$payload['customer_public_id'] ?? null,'events'=>[['name'=>$name,'params'=>$params]]],
            'timeout'=>10.0,
        ]);
        return ['status'=>$response->getStatusCode(),'body'=>mb_substr($response->getContent(false),0,1000)];
    }

    private function deterministicClientId(string $eventId): string { return sprintf('%u.%u',crc32($eventId),crc32(strrev($eventId))); }
    private function eventName(string $name): string
    {
        return match ($name) {
            'commerce.order.placed'=>'purchase', 'commerce.order.completed'=>'purchase_completed', 'commerce.customer.registered'=>'sign_up',
            default=>substr((string)preg_replace('/[^a-z0-9_]+/','_',strtolower($name)),0,40),
        };
    }
    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function cleanParams(array $params): array
    {
        unset($params['consent'],$params['email'],$params['phone'],$params['password'],$params['token']);
        $out=[];
        foreach($params as $key=>$value){if(is_scalar($value)||$value===null){$out[substr((string)$key,0,40)]=$value;} elseif(is_array($value)&&count($value)<=200){$out[substr((string)$key,0,40)]=$value;}}
        return $out;
    }
}
