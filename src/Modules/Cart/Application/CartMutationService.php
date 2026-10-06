<?php

declare(strict_types=1);

namespace Commerce\Modules\Cart\Application;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Catalog\Measurement\Quantity;
use Commerce\Modules\B2B\Application\B2bCommerceService;
use Commerce\Modules\Customer\Application\CustomerStoreMembershipService;
use Commerce\Modules\Storefront\Domain\StorefrontContext;
use Commerce\Modules\Storefront\Infrastructure\DbalStorefrontCatalogQuery;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

final readonly class CartMutationService
{
    public function __construct(
        private Connection $connection,
        private PublicIdFactory $publicIds,
        private DbalStorefrontCatalogQuery $catalog,
        private B2bCommerceService $b2b,
        private \Commerce\Modules\Catalog\Application\ProductAddonService $addons,
        private CustomerStoreMembershipService $memberships,
    ) {
    }

    /**
     * Existing cart for this visitor, or null. Never creates a row, so read-only pages
     * (GET /cart, GET /checkout, crawlers following the header link) do not grow mc_cart.
     *
     * @return array{id:int,token:string,created:bool,currency:string}|null
     */
    public function find(StorefrontContext $context, ?string $token): ?array
    {
        if (!is_string($token) || preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token) !== 1) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            "SELECT id,currency FROM mc_cart WHERE token_hash=? AND store_id=? AND status='active' AND expires_at>UTC_TIMESTAMP(6) LIMIT 1",
            [hash('sha256', $token, true), $context->storeId],
        );
        if (!is_array($row)) {
            return null;
        }
        $currency = (string) $row['currency'];
        if ($currency !== $context->currency && $this->switchCurrency((int) $row['id'], $context)) {
            $currency = $context->currency;
        }

        return ['id' => (int) $row['id'], 'token' => $token, 'created' => false, 'currency' => $currency];
    }

    /** @return array{id:int,token:string,created:bool,currency:string} */
    public function open(StorefrontContext $context, ?string $token): array
    {
        $existing = $this->find($context, $token);
        if ($existing !== null) {
            return $existing;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = $this->now();
        $expires = (new DateTimeImmutable('+7 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $this->connection->insert('mc_cart', [
            'public_id' => $this->publicIds->binary(),
            'store_id' => $context->storeId,
            'customer_id' => null,
            'token_hash' => hash('sha256', $token, true),
            'currency' => $context->currency,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $expires,
        ]);

        return ['id' => (int) $this->connection->lastInsertId(), 'token' => $token, 'created' => true, 'currency' => $context->currency];
    }

    /**
     * Context whose currency matches the cart. A cart only follows the currency switcher when
     * every line is priced in the new currency; otherwise it keeps its own currency so totals
     * are never shown or charged with a price from another currency.
     *
     * @param array{currency?:string} $cart
     */
    public function contextFor(StorefrontContext $context, array $cart): StorefrontContext
    {
        $currency = (string) ($cart['currency'] ?? $context->currency);
        if ($currency === '' || $currency === $context->currency) {
            return $context;
        }

        return new StorefrontContext($context->storeId, $context->marketId, $context->locale, $currency, $context->countryCode, $context->storeName, $context->customerGroup, $context->groupDiscountBps, $context->groupSkipsSale);
    }

    private function switchCurrency(int $cartId, StorefrontContext $context): bool
    {
        return $this->connection->transactional(function (Connection $db) use ($cartId, $context): bool {
            $cart = $db->fetchAssociative("SELECT id,store_id,customer_id FROM mc_cart WHERE id=? AND status='active' FOR UPDATE", [$cartId]);
            if (!is_array($cart)) {
                return false;
            }
            $items = $db->fetchAllAssociative('SELECT ci.id,ci.quantity,ci.metadata,v.public_id,v.product_id FROM mc_cart_item ci JOIN mc_product_variant v ON v.id=ci.variant_id WHERE ci.cart_id=?', [$cartId]);
            $prices = [];
            foreach ($items as $item) {
                $variant = $this->catalog->purchasableVariant($context, \Symfony\Component\Uid\Uuid::fromBinary((string) $item['public_id'])->toRfc4122());
                if ($variant === null || (string) ($variant['currency'] ?? $context->currency) !== $context->currency) {
                    return false;
                }
                $prices[(int) $item['id']] = $this->b2b->priceFor((int) $cart['store_id'], $cart['customer_id'] === null ? null : (int) $cart['customer_id'], (int) $variant['variant_id'], (string) $item['quantity'], \Commerce\Modules\Catalog\Application\ProductAddonService::unitPrice((int) $variant['price_minor'], $this->addons->fromMetadata((int) $item['product_id'], (int) $cart['store_id'], $context->locale, $context->currency, $item['metadata'] !== null ? (string) $item['metadata'] : null)), $context->currency);
            }
            foreach ($prices as $itemId => $price) {
                $db->update('mc_cart_item', ['unit_price_minor' => $price, 'updated_at' => $this->now()], ['id' => $itemId, 'cart_id' => $cartId]);
            }
            $db->update('mc_cart', ['currency' => $context->currency, 'updated_at' => $this->now()], ['id' => $cartId]);

            return true;
        });
    }

    public function bindCustomer(int $cartId, int $storeId, int $customerId): void
    {
        $this->memberships->ensure($storeId, $customerId);
        $this->connection->executeStatement("UPDATE mc_cart SET customer_id=?,updated_at=? WHERE id=? AND store_id=? AND status='active'",[$customerId,$this->now(),$cartId,$storeId]);
    }

    public function add(StorefrontContext $context, int $cartId, string $variantPublicId, string $quantity, array $addonInput = []): void
    {
        $variant = $this->catalog->purchasableVariant($context, $variantPublicId);
        if ($variant === null) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.application.cartmutationservice.tovar_nedostupnyi_dlia_pokupky'));
        }
        $requested = Quantity::fromString($quantity);
        $this->assertQuantity($requested, $variant['min_quantity'], $variant['max_quantity'], $variant['quantity_step']);
        $addons = $this->addons->resolve((int) $variant['product_id'], $context->storeId, $context->locale, $context->currency, $addonInput);
        $variant['price_minor'] = \Commerce\Modules\Catalog\Application\ProductAddonService::unitPrice((int) $variant['price_minor'], $addons);

        $this->connection->transactional(function (Connection $db) use ($cartId, $variant, $requested, $addons): void {
            $cart = $db->fetchAssociative("SELECT id,store_id,customer_id,currency FROM mc_cart WHERE id=? AND status='active' AND expires_at>UTC_TIMESTAMP(6) FOR UPDATE", [$cartId]);
            if (!is_array($cart)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.koshyk_bilshe_ne_aktyvnyi'));
            }
            $existing = $db->fetchOne('SELECT quantity FROM mc_cart_item WHERE cart_id=? AND variant_id=? AND addon_hash=? FOR UPDATE', [$cartId, $variant['variant_id'], $addons['hash']]);
            $totalMicros = $requested->micros + ($existing === false ? 0 : Quantity::fromString((string) $existing)->micros);
            $total = Quantity::fromMicros($totalMicros);
            $this->assertQuantity($total, $variant['min_quantity'], $variant['max_quantity'], $variant['quantity_step']);
            if ($variant['product_type'] !== 'digital' && !($variant['allow_backorder'] ?? false) && $totalMicros > Quantity::fromString($variant['available_quantity'])->micros) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.nedostatno_tovaru_v_naiavnosti'));
            }
            if ($existing === false) {
                $db->insert('mc_cart_item', [
                    'cart_id' => $cartId,
                    'variant_id' => $variant['variant_id'],
                    'quantity' => $total->toDatabase(),
                    'unit_code' => $variant['unit_code'],
                    'unit_price_minor' => $this->b2b->priceFor((int)$cart['store_id'],$cart['customer_id']===null?null:(int)$cart['customer_id'],(int)$variant['variant_id'],$total->toDatabase(),(int)$variant['price_minor'],(string)$cart['currency']),
                    'metadata' => \Commerce\Modules\Catalog\Application\ProductAddonService::metadata($addons['selections']),
                    'addon_hash' => $addons['hash'],
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
            } else {
                $db->update('mc_cart_item', ['quantity' => $total->toDatabase(), 'unit_price_minor' => $this->b2b->priceFor((int)$cart['store_id'],$cart['customer_id']===null?null:(int)$cart['customer_id'],(int)$variant['variant_id'],$total->toDatabase(),(int)$variant['price_minor'],(string)$cart['currency']), 'updated_at' => $this->now()], ['cart_id' => $cartId, 'variant_id' => $variant['variant_id'], 'addon_hash' => $addons['hash']]);
            }
            $this->touch($db, $cartId);
        });
    }

    public function update(StorefrontContext $context, int $cartId, int $itemId, string $quantity): void
    {
        $requested = Quantity::fromString($quantity);
        if (!$requested->isPositive()) {
            $this->remove($cartId, $itemId);
            return;
        }

        $this->connection->transactional(function (Connection $db) use ($context, $cartId, $itemId, $requested): void {
            $cart = $db->fetchAssociative("SELECT id,store_id,customer_id,currency FROM mc_cart WHERE id=? AND status='active' AND expires_at>UTC_TIMESTAMP(6) FOR UPDATE", [$cartId]);
            if (!is_array($cart)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.koshyk_bilshe_ne_aktyvnyi'));
            }
            $row = $db->fetchAssociative('SELECT v.public_id,v.product_id,ci.metadata FROM mc_cart_item ci JOIN mc_product_variant v ON v.id=ci.variant_id WHERE ci.id=? AND ci.cart_id=? FOR UPDATE', [$itemId, $cartId]);
            if (!is_array($row)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.application.cartmutationservice.pozytsiiu_koshyka_ne_znaideno'));
            }
            $variant = $this->catalog->purchasableVariant($context, \Symfony\Component\Uid\Uuid::fromBinary((string) $row['public_id'])->toRfc4122());
            if ($variant === null) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.application.cartmutationservice.tovar_bilshe_nedostupnyi'));
            }
            $this->assertQuantity($requested, $variant['min_quantity'], $variant['max_quantity'], $variant['quantity_step']);
            if ($variant['product_type'] !== 'digital' && !($variant['allow_backorder'] ?? false) && $requested->micros > Quantity::fromString($variant['available_quantity'])->micros) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.nedostatno_tovaru_v_naiavnosti'));
            }
            $db->update('mc_cart_item', [
                'quantity' => $requested->toDatabase(),
                'unit_price_minor' => $this->b2b->priceFor((int)$cart['store_id'],$cart['customer_id']===null?null:(int)$cart['customer_id'],(int)$variant['variant_id'],$requested->toDatabase(),\Commerce\Modules\Catalog\Application\ProductAddonService::unitPrice((int)$variant['price_minor'],$this->addons->fromMetadata((int)$row['product_id'],(int)$cart['store_id'],$context->locale,(string)$cart['currency'],$row['metadata']!==null?(string)$row['metadata']:null)),(string)$cart['currency']),
                'unit_code' => $variant['unit_code'],
                'updated_at' => $this->now(),
            ], ['id' => $itemId, 'cart_id' => $cartId]);
            $this->touch($db, $cartId);
        });
    }

    public function setVariantQuantity(StorefrontContext $context, int $cartId, string $variantPublicId, string $quantity): void
    {
        $requested = Quantity::fromString($quantity);
        $itemId = $this->connection->fetchOne(
            'SELECT ci.id FROM mc_cart_item ci JOIN mc_product_variant pv ON pv.id=ci.variant_id WHERE ci.cart_id=? AND pv.public_id=? LIMIT 1',
            [$cartId, \Symfony\Component\Uid\Uuid::fromString($variantPublicId)->toBinary()]
        );
        if (!$requested->isPositive()) {
            if ($itemId !== false) $this->remove($cartId, (int)$itemId);
            return;
        }
        if ($itemId === false) {
            $this->add($context, $cartId, $variantPublicId, $quantity);
            return;
        }
        $this->update($context, $cartId, (int)$itemId, $quantity);
    }

    public function remove(int $cartId, int $itemId): void
    {
        $this->connection->transactional(function (Connection $db) use ($cartId, $itemId): void {
            $cart = $db->fetchAssociative("SELECT id FROM mc_cart WHERE id=? AND status='active' AND expires_at>UTC_TIMESTAMP(6) FOR UPDATE", [$cartId]);
            if (!is_array($cart)) {
                return;
            }
            $db->delete('mc_cart_item', ['id' => $itemId, 'cart_id' => $cartId]);
            $this->touch($db, $cartId);
        });
    }

    private function assertQuantity(Quantity $quantity, string $min, ?string $max, string $step): void
    {
        $minQ = Quantity::fromString($min); $stepQ = Quantity::fromString($step);
        if (!$quantity->isPositive() || $quantity->micros < $minQ->micros) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.application.cartmutationservice.kilkist_mensha_za_minimalno_dozvolenu'));
        }
        if ($max !== null && $quantity->micros > Quantity::fromString($max)->micros) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.application.cartmutationservice.kilkist_perevyshchuie_maksymalno_dozvolenu'));
        }
        if ($stepQ->micros <= 0 || (($quantity->micros - $minQ->micros) % $stepQ->micros) !== 0) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.application.cartmutationservice.kilkist_ne_vidpovidaie_kroku_prodazhu'));
        }
    }

    private function touch(Connection $db, int $cartId): void
    {
        $db->update('mc_cart', ['updated_at'=>$this->now(),'expires_at'=>(new DateTimeImmutable('+7 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')], ['id'=>$cartId]);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
