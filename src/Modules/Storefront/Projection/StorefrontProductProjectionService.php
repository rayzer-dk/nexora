<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Projection;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

final readonly class StorefrontProductProjectionService
{
    public function __construct(private Connection $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function rebuildProduct(int $productId): array
    {
        if ($productId < 1) {
            return [];
        }
        $this->db->delete('mc_storefront_product_projection', ['product_id' => $productId]);
        $contexts = $this->db->fetchAllAssociative(
            "SELECT sp.store_id,mp.market_id,sl.locale_code locale,sc.currency_code currency
             FROM mc_store_product sp
             JOIN mc_market_product mp ON mp.product_id=sp.product_id
             JOIN mc_store_locale sl ON sl.store_id=sp.store_id AND sl.enabled=1
             JOIN mc_store_currency sc ON sc.store_id=sp.store_id AND sc.enabled=1
             WHERE sp.product_id=?",
            [$productId],
        );
        $documents = [];
        foreach ($contexts as $context) {
            $row = $this->buildPayload(
                $productId,
                (int) $context['store_id'],
                (int) $context['market_id'],
                (string) $context['locale'],
                (string) $context['currency'],
            );
            if ($row === null) {
                continue;
            }
            $documents[] = $row;
            $json = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $now = $this->now();
            $this->db->executeStatement(
                "INSERT INTO mc_storefront_product_projection (product_id,store_id,market_id,locale,currency,status,payload,content_hash,updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE status=VALUES(status),payload=VALUES(payload),content_hash=VALUES(content_hash),updated_at=VALUES(updated_at)",
                [$productId,(int)$context['store_id'],(int)$context['market_id'],(string)$context['locale'],(string)$context['currency'],(string)$row['status'],$json,hash('sha256',$json,true),$now],
            );
        }
        return $documents;
    }

    /** @return array{documents:list<array<string,mixed>>,last_product_id:int,product_count:int} */
    public function rebuildBatch(int $afterProductId, int $limit): array
    {
        $limit = min(1000, max(1, $limit));
        $ids = array_map('intval', $this->db->fetchFirstColumn("SELECT id FROM mc_product WHERE id>? ORDER BY id ASC LIMIT {$limit}", [$afterProductId]));
        $documents = [];
        foreach ($ids as $id) {
            array_push($documents, ...$this->rebuildProduct($id));
        }
        return [
            'documents' => $documents,
            'last_product_id' => $ids !== [] ? (int)$ids[array_key_last($ids)] : $afterProductId,
            'product_count' => count($ids),
        ];
    }

    public function maxProductId(): int
    {
        return (int) $this->db->fetchOne('SELECT COALESCE(MAX(id),0) FROM mc_product');
    }

    /** @return array<string,mixed>|null */
    private function buildPayload(int $productId, int $storeId, int $marketId, string $locale, string $currency): ?array
    {
        $row = $this->db->fetchAssociative(
            "SELECT p.id product_id,p.status,pt.name,pt.short_description,v.sku,v.gtin,v.mpn,b.id brand_id,COALESCE(b.name,'') brand_name,
                    COALESCE(pr.amount_minor,0) price_minor,p.updated_at,
                    COALESCE((SELECT SUM(GREATEST(sl.stocked_quantity-sl.reserved_quantity-sl.safety_stock,0)) FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_market_inventory_location mil ON mil.location_id=sl.location_id AND mil.market_id=? WHERE vii.variant_id=v.id),0) available_quantity
             FROM mc_product p
             JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=?
             JOIN mc_market_product mp ON mp.product_id=p.id AND mp.market_id=?
             JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=? AND pt.locale=?
             JOIN mc_product_variant v ON v.product_id=p.id AND v.status='active' AND v.sort_order=0
             LEFT JOIN mc_brand b ON b.id=p.brand_id
             LEFT JOIN mc_price pr ON pr.id=(SELECT px.id FROM mc_price px WHERE px.variant_id=v.id AND px.store_id=? AND (px.market_id=? OR px.market_id IS NULL) AND px.currency=? AND px.customer_group='default' AND px.price_list_id IS NULL AND px.min_quantity<=1 AND (px.max_quantity IS NULL OR px.max_quantity>=1) AND (px.starts_at IS NULL OR px.starts_at<=UTC_TIMESTAMP(6)) AND (px.ends_at IS NULL OR px.ends_at>UTC_TIMESTAMP(6)) ORDER BY (px.market_id IS NOT NULL) DESC,px.priority ASC,px.id DESC LIMIT 1)
             WHERE p.id=? LIMIT 1",
            [$marketId,$storeId,$marketId,$storeId,$locale,$storeId,$marketId,$currency,$productId],
        );
        if (!is_array($row)) {
            return null;
        }
        $categories = array_map('intval', $this->db->fetchFirstColumn('SELECT category_id FROM mc_product_category WHERE product_id=? ORDER BY sort_order,category_id', [$productId]));
        $attributeRows = $this->db->fetchAllAssociative(
            "SELECT ad.code,pav.value_text,pav.value_decimal,pav.value_boolean
             FROM mc_product_attribute_value pav JOIN mc_attribute_definition ad ON ad.id=pav.attribute_id
             WHERE pav.product_id=? AND pav.variant_id IS NULL AND (pav.locale IS NULL OR pav.locale=?) AND ad.filterable=1
             ORDER BY ad.sort_order,pav.id LIMIT 120",
            [$productId,$locale],
        );
        $tokens=[]; $text=[];
        foreach ($attributeRows as $attribute) {
            $code=(string)$attribute['code'];
            if ($attribute['value_boolean'] !== null) { $value='b:'.((int)$attribute['value_boolean']); $label=((int)$attribute['value_boolean'])===1?'true':'false'; }
            elseif ($attribute['value_decimal'] !== null) { $label=rtrim(rtrim((string)$attribute['value_decimal'],'0'),'.'); $value='d:'.$label; }
            else { $label=trim((string)$attribute['value_text']); if($label==='') continue; $value='t:'.hash('sha256',mb_strtolower($label,'UTF-8')); }
            $tokens[]=$code.'='.$value; $text[]=$label;
        }
        $published = (string)$row['status'] === 'published';
        return [
            'id' => implode('_', [$storeId,$marketId,str_replace('-','_',$locale),$currency,$productId]),
            'product_id' => $productId,
            'store_id' => $storeId,
            'market_id' => $marketId,
            'locale' => $locale,
            'currency' => $currency,
            'status' => $published ? 'published' : (string)$row['status'],
            'name' => (string)$row['name'],
            'name_sort' => mb_strtolower((string)$row['name'],'UTF-8'),
            'short_description' => (string)($row['short_description'] ?? ''),
            'sku' => (string)$row['sku'],
            'gtin' => (string)($row['gtin'] ?? ''),
            'mpn' => (string)($row['mpn'] ?? ''),
            'brand_id' => $row['brand_id'] !== null ? (int)$row['brand_id'] : 0,
            'brand_name' => (string)$row['brand_name'],
            'category_ids' => $categories,
            'attribute_tokens' => $tokens,
            'attribute_text' => implode(' ', $text),
            'price_minor' => (int)$row['price_minor'],
            'available_quantity' => (float)$row['available_quantity'],
            'in_stock' => (float)$row['available_quantity'] > 0,
            'updated_ts' => strtotime((string)$row['updated_at']) ?: 0,
        ];
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
