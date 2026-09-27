<?php

declare(strict_types=1);
namespace Commerce\Modules\Rewards\Http;

use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Rewards\Application\GiftCardService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RewardsAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts,private readonly GiftCardService $cards,private readonly Connection $db){}

    #[Route('/admin/rewards',name:'admin_rewards',methods:['GET','POST'])]
    public function index(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request); $issued=null;
        if($request->isMethod('POST')){
            if(!$this->isCsrfTokenValid('admin_rewards',(string)$request->request->get('_token'))) throw $this->createAccessDeniedException();
            $action=(string)$request->request->get('action');
            try{
                if($action==='issue'){
                    $major=(float)str_replace(',','.',(string)$request->request->get('amount','0')); $amount=(int)round($major*100);
                    $issued=$this->cards->issue($ctx->storeId,$amount,(string)$request->request->get('currency',$ctx->currency),null,trim((string)$request->request->get('expires_at'))?:null);
                    $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.rewards.http.rewardsadmincontroller.podarunkovu_kartku_stvoreno_kod_pokazuietsia_lyshe_z'));
                } elseif($action==='loyalty'){
                    $earn=max(0,min(1000,$request->request->getInt('earn_points_per_major',1)));$redeem=max(1,min(10000,$request->request->getInt('redeem_minor_per_point',1)));$min=max(1,min(1000000,$request->request->getInt('min_redeem_points',100)));$enabled=$request->request->getBoolean('enabled');
                    $this->db->executeStatement('INSERT INTO mc_loyalty_config(store_id,enabled,earn_points_per_major,redeem_minor_per_point,min_redeem_points,updated_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),earn_points_per_major=VALUES(earn_points_per_major),redeem_minor_per_point=VALUES(redeem_minor_per_point),min_redeem_points=VALUES(min_redeem_points),updated_at=VALUES(updated_at)',[$ctx->storeId,$enabled?1:0,$earn,$redeem,$min]);
                    $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.rewards.http.rewardsadmincontroller.nalashtuvannia_loialnosti_zberezheno'));
                }
            }catch(\DomainException $e){$this->addFlash('error',$e->getMessage());}
        }
        $cfg=$this->db->fetchAssociative('SELECT * FROM mc_loyalty_config WHERE store_id=?',[$ctx->storeId])?:['enabled'=>1,'earn_points_per_major'=>1,'redeem_minor_per_point'=>1,'min_redeem_points'=>100];
        return $this->render('@storefront/admin/rewards/index.html.twig',['cards'=>$this->cards->list($ctx->storeId),'config'=>$cfg,'issued'=>$issued,'store_currency'=>$ctx->currency]);
    }
}
