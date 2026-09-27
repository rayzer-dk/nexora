<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Http;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class PaymentReturnController extends AbstractController
{
    #[Route('/payment/return/{order}', name:'storefront_payment_return', methods:['GET'])]
    public function __invoke(string $order,Connection $db): Response
    {
        try{$binary=Uuid::fromString($order)->toBinary();}catch(\Throwable){throw $this->createNotFoundException();}
        $row=$db->fetchAssociative('SELECT o.order_number,o.payment_status,o.status,p.provider_code,p.status provider_status FROM mc_sales_order o JOIN mc_payment p ON p.order_id=o.id WHERE o.public_id=? ORDER BY p.id DESC LIMIT 1',[$binary]);
        if(!is_array($row)) throw $this->createNotFoundException();
        return $this->render('@storefront/checkout/payment_return.html.twig',['page_title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.http.paymentreturncontroller.status_oplaty'),'order_public_id'=>$order,'order'=>$row,'seo_head'=>['robots'=>'noindex,nofollow']]);
    }
}
