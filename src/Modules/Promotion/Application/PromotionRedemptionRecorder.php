<?php

declare(strict_types=1);

namespace Commerce\Modules\Promotion\Application;

use Commerce\Modules\Promotion\Domain\PromotionResult;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

final readonly class PromotionRedemptionRecorder
{
    public function __construct(private Connection $db) {}

    public function record(int $orderId, PromotionResult $result, ?int $customerId, ?string $email): void
    {
        if ($result->applied === []) return;
        $now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $email=$email !== null && trim($email)!=='' ? mb_strtolower(trim($email)) : null;
        foreach($result->applied as $applied){
            $this->db->insert('mc_order_promotion',[
                'order_id'=>$orderId,'promotion_id'=>$applied['id'],'name'=>$applied['name'],'coupon_code'=>$applied['code'],'discount_minor'=>$applied['discount_minor'],
                'snapshot_json'=>json_encode($applied,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
            ]);
            $this->db->insert('mc_promotion_redemption',[
                'promotion_id'=>$applied['id'],'order_id'=>$orderId,'customer_id'=>$customerId,'email_normalized'=>$email,'coupon_code'=>$applied['code'],'discount_minor'=>$applied['discount_minor'],'created_at'=>$now,
            ]);
            $this->db->executeStatement('UPDATE mc_promotion SET usage_count=usage_count+1,updated_at=? WHERE id=?',[$now,$applied['id']]);
        }
    }
}
