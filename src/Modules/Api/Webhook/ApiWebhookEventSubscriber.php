<?php

declare(strict_types=1);

namespace Commerce\Modules\Api\Webhook;

use Commerce\Core\Event\DomainEventSubscriberInterface;
use Commerce\Core\Event\EventNames;
use Commerce\Core\Event\StoredDomainEvent;
use Commerce\Core\Id\PublicIdFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;

final readonly class ApiWebhookEventSubscriber implements DomainEventSubscriberInterface
{
    public function __construct(private Connection $db,private PublicIdFactory $ids){}
    public function subscriberId():string{return 'api.outbound_webhooks';}
    public function subscribedEvents():array{return [EventNames::ORDER_PLACED,EventNames::ORDER_CANCELLED,EventNames::ORDER_COMPLETED,EventNames::PAYMENT_STATUS_CHANGED,EventNames::PRODUCT_CREATED,EventNames::PRODUCT_UPDATED,EventNames::CUSTOMER_REGISTERED];}

    public function handle(StoredDomainEvent $event):void
    {
        $storeId=(int)($event->payload['store_id']??$event->metadata['store_id']??0); if($storeId<1)return;
        $rows=$this->db->fetchAllAssociative("SELECT public_id,target_url,target_url_hash,events FROM mc_webhook_subscription WHERE store_id=? AND status='active'",[$storeId]);
        foreach($rows as $row){
            $events=json_decode((string)$row['events'],true); if(!is_array($events)||(!in_array('*',$events,true)&&!in_array($event->eventName,$events,true)))continue;
            $subscription=Uuid::fromBinary((string)$row['public_id'])->toRfc4122();
            $payload=json_encode(['id'=>$event->eventId,'type'=>$event->eventName,'version'=>$event->eventVersion,'aggregate'=>['type'=>$event->aggregateType,'id'=>$event->aggregateId],'data'=>$event->payload,'occurred_at'=>$event->occurredAt->format(DATE_ATOM)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            try{$this->db->insert('mc_webhook_delivery',['public_id'=>$this->ids->binary(),'store_id'=>$storeId,'subscription_key'=>$subscription,'event_id'=>Uuid::fromString($event->eventId)->toBinary(),'event_type'=>$event->eventName,'payload'=>$payload,'target_url_hash'=>(string)$row['target_url_hash'],'status'=>'pending','attempts'=>0,'next_attempt_at'=>gmdate('Y-m-d H:i:s.u'),'locked_at'=>null,'lock_token'=>null,'response_status'=>null,'last_error'=>null,'created_at'=>gmdate('Y-m-d H:i:s.u'),'completed_at'=>null]);}catch(UniqueConstraintViolationException){}
        }
    }
}
