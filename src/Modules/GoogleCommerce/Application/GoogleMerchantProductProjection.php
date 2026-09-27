<?php

declare(strict_types=1);

namespace Commerce\Modules\GoogleCommerce\Application;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final readonly class GoogleMerchantProductProjection
{
    public function __construct(
        private Connection $db,
        private CanonicalMerchantProductBuilder $canonical,
        private string $publicBaseUrl,
    ) {}

    /** @return array{product_id:int,store_id:int,status:string,canonical:array<string,mixed>,input:array<string,mixed>} */
    public function project(string $productPublicId, ?int $storeId = null): array
    {
        $binary = Uuid::fromString($productPublicId)->toBinary();
        $params = [$binary];
        $storeFilter = '';
        if ($storeId !== null && $storeId > 0) {
            $storeFilter = ' AND sp.store_id=?';
            $params[] = $storeId;
        }
        $row = $this->db->fetchAssociative(
            "SELECT p.id product_id,p.status product_status,p.manufacturer_part_number,p.brand_id,sp.store_id,sp.status publication_status,
                    s.default_locale,s.default_currency,s.default_country,pt.name,pt.short_description,pt.description,
                    v.id variant_id,v.sku,v.gtin,COALESCE(v.mpn,p.manufacturer_part_number) mpn,v.manage_inventory,v.allow_backorder,
                    pr.amount_minor,pr.currency,COALESCE(b.name,'') brand,sr.path
             FROM mc_product p
             JOIN mc_store_product sp ON sp.product_id=p.id{$storeFilter}
             JOIN mc_store s ON s.id=sp.store_id
             JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=sp.store_id AND pt.locale=s.default_locale
             JOIN mc_product_variant v ON v.product_id=p.id AND v.sort_order=0
             LEFT JOIN mc_price pr ON pr.variant_id=v.id AND pr.store_id=sp.store_id AND pr.customer_group='default' AND pr.price_list_id IS NULL AND pr.max_quantity IS NULL AND pr.starts_at IS NULL AND pr.ends_at IS NULL
             LEFT JOIN mc_brand b ON b.id=p.brand_id
             LEFT JOIN mc_seo_route sr ON sr.store_id=sp.store_id AND sr.locale=s.default_locale AND sr.entity_type='product' AND sr.entity_public_id=p.public_id
             WHERE p.public_id=? ORDER BY sp.store_id LIMIT 1",
            array_merge(array_slice($params, 1), [$binary])
        );
        if (!is_array($row)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9ad24bb74d3d'));
        }
        $images = $this->db->fetchFirstColumn(
            'SELECT a.storage_key FROM mc_product_media pm JOIN mc_media_asset a ON a.id=pm.media_asset_id WHERE pm.product_id=? ORDER BY (pm.role=\'primary\') DESC,pm.sort_order,pm.media_asset_id',
            [(int)$row['product_id']]
        );
        $stock = 0.0;
        if ((int)$row['manage_inventory'] === 1) {
            $stock = (float)($this->db->fetchOne('SELECT COALESCE(SUM(sl.stocked_quantity-sl.reserved_quantity-sl.safety_stock),0) FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id WHERE vii.variant_id=?', [(int)$row['variant_id']]) ?: 0);
        } else {
            $stock = 1.0;
        }
        $published = (string)$row['product_status'] === 'published' && (string)$row['publication_status'] === 'active';
        $availability = $stock > 0 ? 'in_stock' : ((int)$row['allow_backorder'] === 1 ? 'backorder' : 'out_of_stock');
        $minor = max(0, (int)($row['amount_minor'] ?? 0));
        $currency = strtoupper((string)($row['currency'] ?: $row['default_currency']));
        $price = number_format($minor / 100, 2, '.', '');
        $base = rtrim($this->publicBaseUrl, '/');
        $canonical = $this->canonical->build([
            'id'=>(string)$row['sku'], 'name'=>(string)$row['name'], 'description'=>strip_tags((string)($row['description'] ?: $row['short_description'] ?: '')),
            'url'=>$base . '/' . ltrim((string)($row['path'] ?: 'product/' . $productPublicId), '/'),
            'images'=>array_map(static fn($key): string => $base . '/media/' . ltrim((string)$key, '/'), $images),
            'price'=>$price, 'currency'=>$currency, 'availability'=>$availability, 'brand'=>(string)$row['brand'] ?: null,
            'gtin'=>(string)$row['gtin'] ?: null, 'mpn'=>(string)$row['mpn'] ?: null,
            'content_language'=>strtolower(substr((string)$row['default_locale'],0,2)), 'feed_label'=>(string)$row['default_country'],
        ]);
        $attrs = [
            'title'=>$canonical['title'], 'description'=>$canonical['description'], 'link'=>$canonical['link'],
            'availability'=>strtoupper((string)$canonical['availability']), 'condition'=>'NEW',
            'price'=>['amountMicros'=>(string)($minor * 10000),'currencyCode'=>$currency],
        ];
        if (!empty($canonical['image_links'][0])) $attrs['imageLink']=$canonical['image_links'][0];
        if (count($canonical['image_links']) > 1) $attrs['additionalImageLinks']=array_slice($canonical['image_links'],1,10);
        if (!empty($canonical['brand'])) $attrs['brand']=$canonical['brand'];
        if (!empty($canonical['gtins'])) $attrs['gtins']=$canonical['gtins'];
        if (!empty($canonical['mpn'])) $attrs['mpn']=$canonical['mpn'];
        return [
            'product_id'=>(int)$row['product_id'],'store_id'=>(int)$row['store_id'],'status'=>$published ? 'published' : 'inactive','canonical'=>$canonical,
            'input'=>['offerId'=>(string)$canonical['offer_id'],'contentLanguage'=>(string)$canonical['content_language'],'feedLabel'=>(string)$canonical['feed_label'],'productAttributes'=>$attrs],
        ];
    }
}
