<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Http;

use Commerce\Modules\Payment\Application\PaymentLifecycleService;
use Commerce\Modules\Payment\Application\PaymentProviderRegistry;
use Commerce\Modules\Payment\Provider\PayPal\PayPalPaymentProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class PaymentReturnController extends AbstractController
{
    #[Route('/payment/return/{order}', name:'storefront_payment_return', methods:['GET'])]
    public function __invoke(string $order,Connection $db,PaymentProviderRegistry $providers,PaymentLifecycleService $lifecycle): Response
    {
        try{$binary=Uuid::fromString($order)->toBinary();}catch(\Throwable){throw $this->createNotFoundException();}
        $row=$db->fetchAssociative('SELECT o.order_number,o.payment_status,o.status,p.provider_code,p.status provider_status FROM mc_sales_order o JOIN mc_payment p ON p.order_id=o.id WHERE o.public_id=? ORDER BY p.id DESC LIMIT 1',[$binary]);
        if(!is_array($row)) throw $this->createNotFoundException();
        // PayPal takes the money only after the buyer is back: capture it here (the webhook is a backup).
        if($row['provider_code']==='paypal' && !in_array((string)$row['provider_status'],['paid','refunded','partially_refunded'],true)){
            try{
                $reference=(string)$db->fetchOne('SELECT p.provider_reference FROM mc_payment p JOIN mc_sales_order o ON o.id=p.order_id WHERE o.public_id=? ORDER BY p.id DESC LIMIT 1',[$binary]);
                $provider=$providers->require('paypal');
                $captured=$provider instanceof PayPalPaymentProvider && $reference!==''?$provider->capture($reference):null;
                if($captured!==null && $captured['status']==='success'){
                    $lifecycle->applyProviderStatus('paypal',$reference,'success',null,$captured['amount_minor'],$captured['currency'],null,$captured['payload']);
                    $row=$db->fetchAssociative('SELECT o.order_number,o.payment_status,o.status,p.provider_code,p.status provider_status FROM mc_sales_order o JOIN mc_payment p ON p.order_id=o.id WHERE o.public_id=? ORDER BY p.id DESC LIMIT 1',[$binary])?:$row;
                }
            }catch(\Throwable){ /* the page shows the current status; the webhook or a reload retries */ }
        }
        return $this->render('@storefront/checkout/payment_return.html.twig',['page_title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.http.paymentreturncontroller.status_oplaty'),'order_public_id'=>$order,'order'=>$row,'seo_head'=>['robots'=>'noindex,nofollow']]);
    }
}
