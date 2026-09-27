<?php

declare(strict_types=1);

namespace Commerce\Modules\Api\Webhook;

use Commerce\Core\Event\EventNames;
use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Core\Id\PublicIdFactory;
use Commerce\Core\Security\OutboundUrlPolicy;
use Commerce\Core\Security\SecretVault;
use Commerce\Modules\Api\Application\ApiAccessException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class ApiWebhookSubscriptionService
{
    private const EVENTS = [
        EventNames::ORDER_PLACED,
        EventNames::ORDER_CANCELLED,
        EventNames::ORDER_COMPLETED,
        EventNames::PAYMENT_STATUS_CHANGED,
        EventNames::PRODUCT_CREATED,
        EventNames::PRODUCT_UPDATED,
        EventNames::CUSTOMER_REGISTERED,
    ];

    public function __construct(
        private Connection $db,
        private PublicIdFactory $ids,
        private SecretVault $vault,
        private OutboundUrlPolicy $urls,
    ) {}

    /** @return list<array<string,mixed>> */
    public function list(int $storeId): array
    {
        $rows=$this->db->fetchAllAssociative('SELECT public_id,name,target_url,events,status,last_delivery_at,created_at,updated_at FROM mc_webhook_subscription WHERE store_id=? ORDER BY id DESC',[$storeId]);
        foreach($rows as &$row){$row['public_id']=Uuid::fromBinary((string)$row['public_id'])->toRfc4122();$events=json_decode((string)$row['events'],true);$row['events']=is_array($events)?array_values(array_map('strval',$events)):[];}
        return $rows;
    }

    /** @param list<string> $events @return array{public_id:string,name:string,target_url:string,events:list<string>,status:string,secret:string} */
    public function create(int $storeId,int $tokenId,string $idempotencyKey,string $requestHash,string $name,string $targetUrl,array $events): array
    {
        $creationKeyHash=hash('sha256',$tokenId.':'.$idempotencyKey,true);
        $existing=$this->db->fetchAssociative('SELECT public_id,name,target_url,events,status,secret_cipher,creation_request_hash FROM mc_webhook_subscription WHERE store_id=? AND creation_key_hash=? LIMIT 1',[$storeId,$creationKeyHash]);
        if(is_array($existing)){
            if(!hash_equals(bin2hex((string)$existing['creation_request_hash']),$requestHash))throw new ApiAccessException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6154abc5032b'),CanonicalUiText::get('api.error.idempotency_conflict'),409);
            $publicId=Uuid::fromBinary((string)$existing['public_id'])->toRfc4122();$decoded=json_decode((string)$existing['events'],true);
            return ['public_id'=>$publicId,'name'=>(string)$existing['name'],'target_url'=>(string)$existing['target_url'],'events'=>is_array($decoded)?array_values(array_map('strval',$decoded)):[],'status'=>(string)$existing['status'],'secret'=>$this->vault->decrypt((string)$existing['secret_cipher'],$this->context($publicId))];
        }
        $name=mb_substr(trim(strip_tags($name)),0,190,'UTF-8');
        if($name==='')throw new \DomainException(CanonicalUiText::get('api.error.webhook_name_required'));
        try{$this->urls->assertPublicHttps($targetUrl,false);}catch(\Throwable){throw new \DomainException(CanonicalUiText::get('api.error.webhook_url_invalid'));}
        $events=$this->normalizeEvents($events);
        $public=$this->ids->generate();
        $publicId=$public->toRfc4122();
        $secret=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $now=$this->now();
        $this->db->insert('mc_webhook_subscription',[
            'public_id'=>$public->toBinary(),'store_id'=>$storeId,'name'=>$name,'target_url'=>trim($targetUrl),'target_url_hash'=>hash('sha256',trim($targetUrl),true),
            'secret_cipher'=>$this->vault->encrypt($secret,$this->context($publicId)),'events'=>json_encode($events,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),'creation_key_hash'=>$creationKeyHash,'creation_request_hash'=>hex2bin($requestHash),'status'=>'active',
            'last_delivery_at'=>null,'created_at'=>$now,'updated_at'=>$now,'disabled_at'=>null,
        ]);
        return ['public_id'=>$publicId,'name'=>$name,'target_url'=>trim($targetUrl),'events'=>$events,'status'=>'active','secret'=>$secret];
    }

    public function disable(int $storeId,string $publicId): bool
    {
        $binary=$this->uuid($publicId); if($binary===null)return false;
        return $this->db->executeStatement("UPDATE mc_webhook_subscription SET status='disabled',disabled_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE store_id=? AND public_id=? AND status<>'disabled'",[$storeId,$binary])===1;
    }

    /** @return array{secret:string}|null */
    public function rotateSecret(int $storeId,string $publicId): ?array
    {
        $binary=$this->uuid($publicId); if($binary===null)return null;
        $exists=$this->db->fetchOne('SELECT id FROM mc_webhook_subscription WHERE store_id=? AND public_id=? LIMIT 1',[$storeId,$binary]); if($exists===false)return null;
        $secret=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $this->db->update('mc_webhook_subscription',['secret_cipher'=>$this->vault->encrypt($secret,$this->context($publicId)),'updated_at'=>$this->now()],['id'=>(int)$exists]);
        return ['secret'=>$secret];
    }

    /** @return list<string> */
    private function normalizeEvents(array $events): array
    {
        $events=array_values(array_unique(array_filter(array_map(static fn($v):string=>trim((string)$v),$events))));
        if($events===[])throw new \DomainException(CanonicalUiText::get('api.error.webhook_events_required'));
        foreach($events as $event)if($event!=='*'&&!in_array($event,self::EVENTS,true))throw new \DomainException(CanonicalUiText::get('api.error.webhook_event_invalid',['event'=>$event]));
        return $events;
    }

    private function uuid(string $publicId): ?string { try{return Uuid::fromString($publicId)->toBinary();}catch(\Throwable){return null;} }
    private function context(string $publicId): string{return 'api-webhook:'.$publicId;}
    private function now():string{return gmdate('Y-m-d H:i:s.u');}
}
