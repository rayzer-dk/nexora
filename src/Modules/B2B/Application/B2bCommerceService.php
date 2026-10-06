<?php

declare(strict_types=1);

namespace Commerce\Modules\B2B\Application;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Commerce\Modules\Payment\Application\PaymentLifecycleService;
use Symfony\Component\Uid\Uuid;

final readonly class B2bCommerceService
{
    public function __construct(private Connection $db, private PaymentLifecycleService $payments, private ?\Commerce\Modules\Catalog\Application\ProductExtrasService $tiers = null) {}

    /** @return array<string,mixed>|null */
    public function membership(int $storeId, ?int $customerId): ?array
    {
        if ($customerId === null) return null;
        $row=$this->db->fetchAssociative("SELECT c.id company_id,c.name,c.legal_name,c.tax_id,c.vat_id,c.currency,c.credit_limit_minor,c.payment_terms_days,c.approval_threshold_minor,m.role,m.spending_limit_minor FROM mc_b2b_company_member m JOIN mc_b2b_company c ON c.id=m.company_id WHERE m.customer_id=? AND m.status='active' AND c.store_id=? AND c.status='active' ORDER BY FIELD(m.role,'admin','approver','buyer'),c.id LIMIT 1",[$customerId,$storeId]);
        return is_array($row)?$row:null;
    }

    public function priceFor(int $storeId, ?int $customerId, int $variantId, string $quantity, int $retailMinor, string $currency): int
    {
        $retailMinor=$this->tiers?->tierPrice($variantId,$storeId,strtoupper($currency),$quantity,$retailMinor) ?? $retailMinor;
        $m=$this->membership($storeId,$customerId); if($m===null || strtoupper((string)$m['currency'])!==strtoupper($currency)) return $retailMinor;
        $price=$this->db->fetchOne("SELECT t.amount_minor FROM mc_b2b_price_tier t JOIN mc_b2b_price_list pl ON pl.id=t.price_list_id JOIN mc_b2b_price_list_company pc ON pc.price_list_id=pl.id WHERE pc.company_id=? AND pl.store_id=? AND pl.currency=? AND pl.status='active' AND (pl.starts_at IS NULL OR pl.starts_at<=UTC_TIMESTAMP(6)) AND (pl.ends_at IS NULL OR pl.ends_at>UTC_TIMESTAMP(6)) AND t.variant_id=? AND t.min_quantity<=? AND (t.max_quantity IS NULL OR t.max_quantity>=?) ORDER BY pl.priority ASC,t.min_quantity DESC,t.id DESC LIMIT 1",[(int)$m['company_id'],$storeId,strtoupper($currency),$variantId,$quantity,$quantity]);
        return $price===false?$retailMinor:min($retailMinor,max(0,(int)$price));
    }

    /** @return array<string,mixed>|null */
    public function checkoutTerms(int $storeId, ?int $customerId, int $totalMinor): ?array
    {
        $m=$this->membership($storeId,$customerId); if($m===null) return null;
        $memberLimit=$m['spending_limit_minor']===null?null:(int)$m['spending_limit_minor'];
        if($memberLimit!==null && $totalMinor>$memberLimit) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.b2b.application.b2bcommerceservice.suma_b2b_zamovlennia_perevyshchuie_limit_tsoho_spivr'));
        $credit=(int)$m['credit_limit_minor'];
        if($credit>0){$open=(int)$this->db->fetchOne("SELECT COALESCE(SUM(total_minor),0) FROM mc_sales_order WHERE b2b_company_id=? AND payment_status NOT IN ('paid','refunded','cancelled') AND status NOT IN ('cancelled')",[(int)$m['company_id']]); if($open+$totalMinor>$credit) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.b2b.application.b2bcommerceservice.kredytnyi_limit_kompanii_perevyshcheno'));}
        $threshold=$m['approval_threshold_minor']===null?null:(int)$m['approval_threshold_minor'];
        $approval=($threshold!==null && $totalMinor>$threshold && !in_array((string)$m['role'],['approver','admin'],true))?'pending':'approved';
        return [...$m,'approval_status'=>$approval];
    }

    public function approveOrder(int $storeId, int $orderId, bool $approved): void
    {
        $row=$this->db->fetchAssociative("SELECT public_id FROM mc_sales_order WHERE id=? AND store_id=? AND b2b_company_id IS NOT NULL AND b2b_approval_status='pending' LIMIT 1",[$orderId,$storeId]);
        if(!is_array($row)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.b2b.application.b2bcommerceservice.b2b_zamovlennia_ne_znaideno_abo_rishennia_vzhe_pryin'));
        $publicId=Uuid::fromBinary((string)$row['public_id'])->toRfc4122();
        if(!$approved){ $this->db->update('mc_sales_order',['b2b_approval_status'=>'rejected','updated_at'=>$this->now()],['id'=>$orderId]); $this->payments->cancelOrder($publicId,'b2b_approval'); return; }
        $this->db->update('mc_sales_order',['b2b_approval_status'=>'approved','updated_at'=>$this->now()],['id'=>$orderId]);
        $this->payments->confirmDeferredPayment($publicId,'b2b_approval');
    }

    private function now(): string { return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'); }
}
