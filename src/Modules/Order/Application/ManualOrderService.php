<?php

declare(strict_types=1);

namespace Commerce\Modules\Order\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Cart\Application\CartMutationService;
use Commerce\Modules\Storefront\Domain\StorefrontContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Order taken by a manager (phone, messenger, a quick-order lead). It goes through the same
 * CheckoutOrderService as the storefront, so validation, stock reservation, promotions, events and
 * notifications behave identically. Only the unit price may be overridden, and only from here.
 */
final readonly class ManualOrderService
{
    public function __construct(
        private Connection $db,
        private CartMutationService $carts,
        private CheckoutOrderService $checkout,
    ) {}

    /**
     * @param array{name:string,phone:string,email:string,comment:string,payment_method:string,delivery:string,coupon:string,lines:list<array{sku:string,quantity:string,price:string}>,inquiry_id?:int} $input
     * @return array{public_id:string,order_number:string,total_minor:int,currency:string}
     */
    public function create(int $storeId, int $marketId, string $locale, string $currency, string $adminSubject, array $input): array
    {
        $market = $this->db->fetchAssociative("SELECT m.id,mc.country_code FROM mc_market m LEFT JOIN mc_market_country mc ON mc.market_id=m.id WHERE m.id=? AND m.store_id=? ORDER BY mc.country_code ASC LIMIT 1", [$marketId, $storeId]);
        if (!is_array($market)) {
            throw new \DomainException(CanonicalUiText::get('storefront.error.market_not_configured'));
        }
        $storeName = (string) ($this->db->fetchOne('SELECT name FROM mc_store WHERE id=?', [$storeId]) ?: '');
        $context = new StorefrontContext($storeId, $marketId, $locale, $currency, (string) ($market['country_code'] ?: 'UA'), $storeName);

        $lines = [];
        foreach ($input['lines'] as $line) {
            $sku = trim($line['sku']);
            if ($sku === '') {
                continue;
            }
            $quantity = str_replace(',', '.', trim($line['quantity'])) ?: '1';
            if (preg_match('/^\d{1,6}(\.\d{1,3})?$/', $quantity) !== 1 || (float) $quantity <= 0) {
                throw new \DomainException(CanonicalUiText::get('admin.manual_order.error_quantity'));
            }
            $priceRaw = str_replace([' ', ','], ['', '.'], trim($line['price']));
            if ($priceRaw !== '' && (!is_numeric($priceRaw) || (float) $priceRaw < 0 || (float) $priceRaw > 9.0e9)) {
                throw new \DomainException(CanonicalUiText::get('admin.manual_order.error_price'));
            }
            $variant = $this->db->fetchAssociative('SELECT v.id,v.public_id FROM mc_product_variant v JOIN mc_store_product sp ON sp.product_id=v.product_id AND sp.store_id=? WHERE v.sku=? LIMIT 1', [$storeId, $sku]);
            if (!is_array($variant)) {
                throw new \DomainException(CanonicalUiText::get('admin.manual_order.error_sku', ['sku' => $sku]));
            }
            $lines[] = ['variant_id' => (int) $variant['id'], 'public_id' => Uuid::fromBinary((string) $variant['public_id'])->toRfc4122(), 'quantity' => $quantity, 'price_minor' => $priceRaw === '' ? null : (int) round((float) $priceRaw * 100)];
        }
        if ($lines === []) {
            throw new \DomainException(CanonicalUiText::get('admin.manual_order.error_no_lines'));
        }

        $email = mb_strtolower(trim($input['email']));
        $customerId = null;
        if ($email !== '') {
            $found = $this->db->fetchOne("SELECT id FROM mc_customer WHERE email_normalized=? AND status='active' LIMIT 1", [$email]);
            $customerId = $found === false ? null : (int) $found;
        }

        $cart = $this->carts->open($context, null);
        $overrides = [];
        try {
            foreach ($lines as $line) {
                $this->carts->add($context, $cart['id'], $line['public_id'], $line['quantity']);
                if ($line['price_minor'] !== null) {
                    $overrides[$line['variant_id']] = $line['price_minor'];
                }
            }
            $result = $this->place($context, $cart['id'], $input, $email, $customerId, $overrides);
        } catch (\Throwable $e) {
            // A failed manual order must not leave a phantom "abandoned cart" behind.
            $this->db->executeStatement('DELETE FROM mc_cart_item WHERE cart_id=?', [$cart['id']]);
            $this->db->executeStatement("DELETE FROM mc_cart WHERE id=? AND status='active'", [$cart['id']]);
            throw $e;
        }

        $orderId = (int) $this->db->fetchOne('SELECT id FROM mc_sales_order WHERE public_id=?', [Uuid::fromString($result['public_id'])->toBinary()]);
        $this->db->executeStatement("UPDATE mc_order_event SET actor_type='admin', actor_subject=? WHERE order_id=? AND sequence_no=1", [mb_substr($adminSubject, 0, 190), $orderId]);
        $inquiryId = (int) ($input['inquiry_id'] ?? 0);
        if ($inquiryId > 0) {
            $note = (string) $this->db->fetchOne('SELECT COALESCE(admin_note,\'\') FROM mc_customer_inquiry WHERE id=? AND store_id=?', [$inquiryId, $storeId]);
            $this->db->executeStatement("UPDATE mc_customer_inquiry SET status='resolved',admin_note=?,updated_at=? WHERE id=? AND store_id=?", [mb_substr(trim($note . "\n#" . $result['order_number']), 0, 4000), gmdate('Y-m-d H:i:s.u'), $inquiryId, $storeId]);
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $input
     * @param array<int,int> $overrides
     * @return array{public_id:string,order_number:string,total_minor:int,currency:string}
     */
    private function place(StorefrontContext $context, int $cartId, array $input, string $email, ?int $customerId, array $overrides): array
    {
        return $this->checkout->place($context, $cartId, [
            'name' => $input['name'],
            'phone' => $input['phone'],
            'email' => $email,
            'customer_comment' => $input['comment'],
            'payment_method' => $input['payment_method'],
            'carrier' => 'manual',
            'delivery_manual' => $input['delivery'] !== '' ? $input['delivery'] : '-',
            'coupon_code' => $input['coupon'],
        ], bin2hex(random_bytes(16)), $customerId, $overrides);
    }
}
