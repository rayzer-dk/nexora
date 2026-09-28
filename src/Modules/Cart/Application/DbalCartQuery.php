<?php

declare(strict_types=1);

namespace Commerce\Modules\Cart\Application;

use Commerce\Modules\Catalog\Measurement\Quantity;
use Commerce\Modules\Storefront\Domain\StorefrontContext;
use Commerce\Modules\Promotion\Application\PromotionEngine;
use Commerce\Modules\Storefront\Infrastructure\StorefrontMoneyFormatter;
use Doctrine\DBAL\Connection;

final readonly class DbalCartQuery
{
    public function __construct(private Connection $connection, private StorefrontMoneyFormatter $money, private PromotionEngine $promotions)
    {
    }

    /** @return array{items:list<array<string,mixed>>,count:int,subtotal_minor:int,subtotal:string,discount_minor:int,discount:string,total_minor:int,total:string,currency:string,requires_shipping:bool} */
    /** Summary of a cart that does not exist yet (nothing persisted for read-only visits). */
    public function emptySummary(StorefrontContext $context): array
    {
        $zero = $this->money->format(0, $context->currency, $context->locale);

        return ['items'=>[],'count'=>0,'subtotal_minor'=>0,'subtotal'=>$zero,'discount_minor'=>0,'discount'=>$zero,'total_minor'=>0,'total'=>$zero,'currency'=>$context->currency,'requires_shipping'=>false];
    }

    public function summary(int $cartId, StorefrontContext $context): array
    {
        $rows = $this->connection->fetchAllAssociative(
            // A product not yet translated into the shopper's language keeps its default-language name and URL:
            // it must never silently disappear from the cart.
            "SELECT ci.id,ci.quantity,ci.unit_code,ci.unit_price_minor,v.sku,v.quantity_step,v.min_order_quantity,v.max_order_quantity,COALESCE(pt.name,ptd.name,v.sku) AS name,p.product_type,COALESCE(sr.path,srd.path) AS path,ma.storage_key
             FROM mc_cart_item ci JOIN mc_product_variant v ON v.id=ci.variant_id JOIN mc_product p ON p.id=v.product_id
             JOIN mc_store st ON st.id=?
             LEFT JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=st.id AND pt.locale=?
             LEFT JOIN mc_product_translation ptd ON ptd.product_id=p.id AND ptd.store_id=st.id AND ptd.locale=st.default_locale
             LEFT JOIN mc_seo_route sr ON sr.store_id=st.id AND sr.locale=? AND sr.entity_type='product' AND sr.entity_public_id=p.public_id
             LEFT JOIN mc_seo_route srd ON srd.store_id=st.id AND srd.locale=st.default_locale AND srd.entity_type='product' AND srd.entity_public_id=p.public_id
             LEFT JOIN mc_media_asset ma ON ma.id=(SELECT pm.media_asset_id FROM mc_product_media pm WHERE pm.product_id=p.id AND pm.role='primary' ORDER BY pm.sort_order ASC,pm.media_asset_id ASC LIMIT 1)
             WHERE ci.cart_id=? ORDER BY ci.created_at ASC,ci.id ASC",
            [$context->storeId,$context->locale,$context->locale,$cartId],
        );
        $subtotal=0; $items=[]; $requiresShipping=false;
        foreach($rows as $row){
            $quantity=Quantity::fromString((string)$row['quantity']); if ((string)$row['product_type'] !== 'digital') $requiresShipping=true; $line=intdiv(((int)$row['unit_price_minor']*$quantity->micros)+500000,1000000); $subtotal+=$line;
            $key=is_string($row['storage_key'])?str_replace('\\','/',trim($row['storage_key'])):'';
            $items[]=['id'=>(int)$row['id'],'name'=>(string)$row['name'],'sku'=>(string)$row['sku'],'quantity'=>rtrim(rtrim((string)$row['quantity'],'0'),'.'),'unit_code'=>(string)$row['unit_code'],'quantity_step'=>rtrim(rtrim((string)$row['quantity_step'],'0'),'.'),'min_quantity'=>rtrim(rtrim((string)$row['min_order_quantity'],'0'),'.'),'max_quantity'=>$row['max_order_quantity']!==null?rtrim(rtrim((string)$row['max_order_quantity'],'0'),'.'):null,'unit_price'=>$this->money->format((int)$row['unit_price_minor'],$context->currency,$context->locale),'line_total'=>$this->money->format($line,$context->currency,$context->locale),'url'=>$row['path']?'/'.ltrim((string)$row['path'],'/'):'#','image'=>$key!==''&&!str_contains($key,'..')?'/media/'.ltrim($key,'/'):'/assets/product-placeholder.svg'];
        }
        $promotion=$items!==[]?$this->promotions->calculateForCart($context->storeId,$cartId):null;
        $discount=$promotion?->discountMinor ?? 0; $total=max(0,$subtotal-$discount);
        return ['items'=>$items,'count'=>count($items),'subtotal_minor'=>$subtotal,'subtotal'=>$this->money->format($subtotal,$context->currency,$context->locale),'discount_minor'=>$discount,'discount'=>$this->money->format($discount,$context->currency,$context->locale),'total_minor'=>$total,'total'=>$this->money->format($total,$context->currency,$context->locale),'currency'=>$context->currency,'requires_shipping'=>$requiresShipping];
    }
}
