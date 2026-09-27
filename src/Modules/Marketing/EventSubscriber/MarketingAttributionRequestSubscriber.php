<?php

declare(strict_types=1);
namespace Commerce\Modules\Marketing\EventSubscriber;
use Commerce\Modules\Marketing\Application\MarketingAttributionService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
final readonly class MarketingAttributionRequestSubscriber
{
    public function __construct(private MarketingAttributionService $attribution){}
    #[AsEventListener(event:KernelEvents::REQUEST,priority:2)] public function onRequest(RequestEvent $event):void{if($event->isMainRequest())$this->attribution->capture($event->getRequest());}
}
