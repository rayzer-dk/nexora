<?php

declare(strict_types=1);
namespace Commerce\Modules\Rewards\Http;

use Commerce\Modules\Customer\Domain\CustomerUser;
use Commerce\Modules\Rewards\Application\LoyaltyService;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RewardsController extends AbstractController
{
    public function __construct(private readonly StorefrontContextResolver $contexts,private readonly LoyaltyService $loyalty){}

    #[Route('/account/rewards',name:'customer_rewards',methods:['GET'],priority:120)]
    public function index(Request $request): Response
    {
        $u=$this->getUser(); if(!$u instanceof CustomerUser) throw $this->createAccessDeniedException(); $ctx=$this->contexts->resolve($request);
        return $this->render('@storefront/account/rewards.html.twig',['page_title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.rewards.http.rewardscontroller.bonusy'),'store_name'=>$ctx->storeName,'account'=>$this->loyalty->account($ctx->storeId,$u->id()),'transactions'=>$this->loyalty->transactions($ctx->storeId,$u->id()),'config'=>$this->loyalty->config($ctx->storeId),'seo_head'=>['robots'=>'noindex,nofollow']]);
    }
}
