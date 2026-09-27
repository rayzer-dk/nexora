<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Application;

use Commerce\Modules\Payment\Contract\OnlinePaymentProviderInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class PaymentCancellationService
{
    public function __construct(private Connection $db, private PaymentProviderRegistry $providers) {}
    public function cancelProviderPayment(string $orderPublicId): void
    {
        $row=$this->db->fetchAssociative('SELECT p.provider_code,p.provider_reference,p.status FROM mc_sales_order o JOIN mc_payment p ON p.order_id=o.id WHERE o.public_id=? ORDER BY p.id DESC LIMIT 1',[Uuid::fromString($orderPublicId)->toBinary()]);
        if(!is_array($row) || trim((string)$row['provider_reference'])==='') return;
        if(in_array((string)$row['status'],['paid','refunded'],true)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentcancellationservice.oplachenyi_rakhunok_ne_mozhna_prosto_skasuvaty_vykor'));
        $provider=$this->providers->require((string)$row['provider_code']);
        if($provider instanceof OnlinePaymentProviderInterface) $provider->cancel((string)$row['provider_reference']);
    }
}
