<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketing\Application;

use Doctrine\DBAL\Connection;

final readonly class MarketingSegmentService
{
    public function __construct(private Connection $db) {}

    /** @return array<string,string> */
    public function labels(): array
    {
        return [
            'all_subscribers'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.marketingsegmentservice.usi_pidtverdzheni_pidpysnyky'),
            'customers'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.marketingsegmentservice.pidpysnyky_z_oblikovym_zapysom'),
            'buyers_90d'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.marketingsegmentservice.pokuptsi_za_ostanni_90_dniv'),
            'repeat_buyers'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.marketingsegmentservice.povtorni_pokuptsi'),
            'lapsed_180d'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.marketingsegmentservice.ne_kupuvaly_ponad_180_dniv'),
        ];
    }

    public function count(int $storeId,string $segment): int
    {
        return count($this->recipients($storeId,$segment,5000,0));
    }

    /** @return list<array{id:int,public_id:string,email:string}> */
    public function recipients(int $storeId,string $segment,int $limit,int $afterId=0): array
    {
        if(!isset($this->labels()[$segment])) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.marketingsegmentservice.nevidomyi_sehment_kampanii'));
        $limit=max(1,min(5000,$limit));
        $condition=match($segment){
            'customers'=>"EXISTS(SELECT 1 FROM mc_customer c WHERE c.email_normalized=s.email_normalized AND c.status='active')",
            'buyers_90d'=>"EXISTS(SELECT 1 FROM mc_sales_order o WHERE o.store_id=s.store_id AND o.customer_email_normalized=s.email_normalized AND o.status NOT IN ('cancelled','expired') AND o.created_at>=UTC_TIMESTAMP(6)-INTERVAL 90 DAY)",
            'repeat_buyers'=>"(SELECT COUNT(*) FROM mc_sales_order o WHERE o.store_id=s.store_id AND o.customer_email_normalized=s.email_normalized AND o.status NOT IN ('cancelled','expired'))>=2",
            'lapsed_180d'=>"EXISTS(SELECT 1 FROM mc_sales_order o WHERE o.store_id=s.store_id AND o.customer_email_normalized=s.email_normalized AND o.status NOT IN ('cancelled','expired')) AND NOT EXISTS(SELECT 1 FROM mc_sales_order o2 WHERE o2.store_id=s.store_id AND o2.customer_email_normalized=s.email_normalized AND o2.status NOT IN ('cancelled','expired') AND o2.created_at>=UTC_TIMESTAMP(6)-INTERVAL 180 DAY)",
            default=>'1=1',
        };
        return $this->db->fetchAllAssociative("SELECT s.id,s.public_id,s.email FROM mc_marketing_subscriber s WHERE s.store_id=? AND s.status='active' AND s.id>? AND $condition ORDER BY s.id ASC LIMIT $limit",[$storeId,$afterId]);
    }
}
