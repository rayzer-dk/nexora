<?php

declare(strict_types=1);

namespace Commerce\Modules\Privacy\Application;

use Commerce\Core\Id\PublicIdFactory;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CustomerPrivacyService
{
    public function __construct(private Connection $db, private PublicIdFactory $ids)
    {
    }

    /** @return array<string,mixed> */
    public function export(int $customerId, int $storeId): array
    {
        $customer = $this->db->fetchAssociative('SELECT public_id,email,phone_e164,display_name,locale,status,created_at,updated_at,last_seen_at FROM mc_customer WHERE id=? LIMIT 1', [$customerId]);
        if (!is_array($customer)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.privacy.application.customerprivacyservice.oblikovyi_zapys_ne_znaideno'));
        }
        $customer['public_id'] = Uuid::fromBinary((string) $customer['public_id'])->toRfc4122();

        $orders = $this->db->fetchAllAssociative('SELECT id,public_id,order_number,status,payment_status,fulfillment_status,currency,subtotal_minor,discount_minor,shipping_minor,tax_minor,total_minor,customer_email,customer_phone,customer_name,locale,created_at,updated_at FROM mc_sales_order WHERE customer_id=? AND store_id=? ORDER BY id ASC', [$customerId, $storeId]);
        foreach ($orders as &$order) {
            $id = (int) $order['id'];
            $order['public_id'] = Uuid::fromBinary((string) $order['public_id'])->toRfc4122();
            $order['items'] = $this->db->fetchAllAssociative('SELECT sku,name,quantity,unit_code,unit_price_minor,line_total_minor,tax_minor FROM mc_sales_order_item WHERE order_id=? ORDER BY id ASC', [$id]);
            unset($order['id']);
        }
        unset($order);

        $reviews = $this->db->fetchAllAssociative('SELECT public_id,rating,title,body,merchant_reply,status,created_at,published_at FROM mc_product_review WHERE customer_id=? AND store_id=? ORDER BY id ASC', [$customerId, $storeId]);
        foreach ($reviews as &$review) {
            $review['public_id'] = Uuid::fromBinary((string) $review['public_id'])->toRfc4122();
        }
        unset($review);

        return ['format'=>'nexora-commerce-customer-export','version'=>1,'generated_at'=>gmdate('c'),'customer'=>$customer,'orders'=>$orders,'reviews'=>$reviews];
    }

    public function requestErasure(int $customerId, int $storeId): string
    {
        $existing = $this->db->fetchOne("SELECT public_id FROM mc_data_subject_request WHERE customer_id=? AND store_id=? AND request_type='erasure' AND status IN ('received','identity_verification','in_progress') ORDER BY id DESC LIMIT 1", [$customerId, $storeId]);
        if (is_string($existing) && strlen($existing) === 16) {
            return Uuid::fromBinary($existing)->toRfc4122();
        }
        $customer = $this->db->fetchAssociative('SELECT email_normalized,phone_e164 FROM mc_customer WHERE id=? AND status=? LIMIT 1', [$customerId, 'active']);
        if (!is_array($customer)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerprofileservice.oblikovyi_zapys_bilshe_ne_aktyvnyi'));
        }
        $contact = (string) ($customer['email_normalized'] ?: $customer['phone_e164'] ?: ('customer:'.$customerId));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $public = $this->ids->generate();
        $this->db->insert('mc_data_subject_request', [
            'public_id'=>$public->toBinary(),'store_id'=>$storeId,'customer_id'=>$customerId,'request_type'=>'erasure','status'=>'received',
            'contact_hash'=>hash('sha256', mb_strtolower(trim($contact)), true),'verification_hash'=>null,
            'requested_at'=>$now->format('Y-m-d H:i:s.u'),'due_at'=>$now->add(new DateInterval('P30D'))->format('Y-m-d H:i:s.u'),'verified_at'=>$now->format('Y-m-d H:i:s.u'),'completed_at'=>null,'rejection_reason'=>null,
            'created_at'=>$now->format('Y-m-d H:i:s.u'),'updated_at'=>$now->format('Y-m-d H:i:s.u'),
        ]);
        return $public->toRfc4122();
    }
}
