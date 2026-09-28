<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Infrastructure;

use Commerce\Modules\Catalog\Application\ProductEditQueryInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class DbalCatalogAdminQuery implements ProductEditQueryInterface
{
    public function __construct(private Connection $connection, private ?\Commerce\Modules\Search\Application\SqlSearchIndex $searchIndex = null)
    {
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int} */
    public function products(int $storeId, int $marketId, string $locale, int $page = 1, int $limit = 25, string $search = ''): array
    {
        $page = max(1, $page); $limit = min(100, max(1, $limit)); $offset = ($page - 1) * $limit;
        $where = 'sp.store_id = ? AND pt.locale = ?'; $params = [$storeId, $locale];
        if (trim($search) !== '') {
            $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($search)) . '%';
            $exact = '(pt.name LIKE ? OR v.sku LIKE ? OR v.gtin LIKE ? OR v.mpn LIKE ? OR b.name LIKE ?)';
            $exactParams = [$needle, $needle, $needle, $needle, $needle];
            if ($this->searchIndex !== null && $this->searchIndex->isAvailable($storeId, $locale)) {
                // Same index as the storefront: every language, categories, word forms and typos.
                $tokens = array_slice(preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 8);
                $prepared = $this->searchIndex->prepare($storeId, $locale, array_map(static fn (string $t): array => [$t], $tokens));
                $groupSql = [];
                $groupParams = [];
                foreach ($prepared['groups'] as $alternatives) {
                    $like = [];
                    foreach (array_slice($alternatives, 0, 8) as $stem) {
                        $like[] = 'sd.document LIKE ?';
                        $groupParams[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $stem) . '%';
                    }
                    $groupSql[] = '(' . implode(' OR ', $like) . ')';
                }
                if ($groupSql !== []) {
                    $where .= ' AND (' . $exact . ' OR EXISTS (SELECT 1 FROM mc_search_document sd WHERE sd.store_id=sp.store_id AND sd.locale=pt.locale AND sd.product_id=p.id AND ' . implode(' AND ', $groupSql) . '))';
                    array_push($params, ...$exactParams, ...$groupParams);
                } else {
                    $where .= ' AND ' . $exact;
                    array_push($params, ...$exactParams);
                }
            } else {
                $where .= ' AND ' . $exact;
                array_push($params, ...$exactParams);
            }
        }
        $total = (int) $this->connection->fetchOne(
            "SELECT COUNT(DISTINCT p.id) FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=sp.store_id LEFT JOIN mc_product_variant v ON v.product_id=p.id LEFT JOIN mc_brand b ON b.id=p.brand_id WHERE {$where}",
            $params,
        );
        $rows = $this->connection->fetchAllAssociative(
            "SELECT p.id,p.public_id,p.status,p.product_type,p.brand_id,pt.name,pt.description,v.sku,v.gtin,b.name AS brand_name,pr.amount_minor,pr.currency, EXISTS(SELECT 1 FROM mc_product_media pm WHERE pm.product_id=p.id LIMIT 1) AS has_image, EXISTS(SELECT 1 FROM mc_seo_route srq WHERE srq.store_id=sp.store_id AND srq.locale=pt.locale AND srq.entity_type='product' AND srq.entity_public_id=p.public_id AND srq.indexable=1 LIMIT 1) AS has_seo FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=sp.store_id LEFT JOIN mc_product_variant v ON v.product_id=p.id AND v.sort_order=0 LEFT JOIN mc_brand b ON b.id=p.brand_id LEFT JOIN mc_price pr ON pr.id=(SELECT p2.id FROM mc_price p2 WHERE p2.variant_id=v.id AND p2.store_id=sp.store_id AND p2.market_id=? AND p2.customer_group='default' AND p2.price_list_id IS NULL AND p2.max_quantity IS NULL AND p2.starts_at IS NULL AND p2.ends_at IS NULL ORDER BY p2.priority ASC,p2.min_quantity ASC,p2.id DESC LIMIT 1) WHERE {$where} ORDER BY p.updated_at DESC,p.id DESC LIMIT {$limit} OFFSET {$offset}",
            array_merge([$marketId], $params),
        );
        foreach ($rows as &$row) { $row['public_id'] = Uuid::fromBinary((string) $row['public_id'])->toRfc4122(); $row['quality']=['image'=>(bool)$row['has_image'],'seo'=>(bool)$row['has_seo'],'gtin'=>trim((string)($row['gtin']??''))!=='','content'=>mb_strlen(trim(strip_tags((string)($row['description']??''))))>=80]; unset($row['has_image'],$row['has_seo'],$row['description']); } unset($row);
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /** @return array<string,mixed> */
    public function productForEdit(int $storeId, int $marketId, string $locale, string $publicId): array
    {
        $binary = Uuid::fromString($publicId)->toBinary();
        $row = $this->connection->fetchAssociative(
            "SELECT p.id,p.public_id,p.status,p.product_type,p.brand_id,pt.name,pt.short_description,pt.description,v.id AS variant_id,v.sku,v.gtin,v.mpn,v.sale_unit_code,pr.amount_minor,pr.currency,sr.slug,COALESCE(ppp.mode,'auto') purchase_mode,ppp.button_label purchase_button_label,ppp.eta_text purchase_eta_text,
                (SELECT sl.stocked_quantity FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_market_inventory_location mil ON mil.location_id=sl.location_id AND mil.market_id=? WHERE vii.variant_id=v.id ORDER BY mil.priority ASC,sl.location_id ASC LIMIT 1) AS stock_quantity
             FROM mc_product p
             JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=?
             JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=? AND pt.locale=?
             JOIN mc_product_variant v ON v.product_id=p.id AND v.sort_order=0
             LEFT JOIN mc_product_purchase_policy ppp ON ppp.product_id=p.id
             LEFT JOIN mc_price pr ON pr.id=(SELECT p2.id FROM mc_price p2 WHERE p2.variant_id=v.id AND p2.store_id=? AND p2.market_id=? AND p2.customer_group='default' AND p2.price_list_id IS NULL AND p2.max_quantity IS NULL AND p2.starts_at IS NULL AND p2.ends_at IS NULL ORDER BY p2.priority ASC,p2.min_quantity ASC,p2.id DESC LIMIT 1)
             LEFT JOIN mc_seo_route sr ON sr.store_id=? AND sr.locale=? AND sr.entity_type='product' AND sr.entity_public_id=p.public_id
             WHERE p.public_id=? LIMIT 1",
            [$marketId, $storeId, $storeId, $locale, $storeId, $marketId, $storeId, $locale, $binary],
        );
        if (!is_array($row)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7fe16b67154e'));
        }
        $row['public_id'] = Uuid::fromBinary((string) $row['public_id'])->toRfc4122();
        $row['category_ids'] = array_map('intval', $this->connection->fetchFirstColumn('SELECT category_id FROM mc_product_category WHERE product_id=? ORDER BY is_primary DESC,sort_order ASC,category_id ASC', [(int) $row['id']]));
        return $row;
    }


    /** @return list<array{id:int,public_id:string,name:string,status:string,sort_order:int}> */
    public function brands(int $storeId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT b.id,b.public_id,b.name,sb.status,sb.sort_order
             FROM mc_brand b
             JOIN mc_store_brand sb ON sb.brand_id=b.id AND sb.store_id=?
             ORDER BY sb.sort_order ASC,b.name ASC,b.id ASC",
            [$storeId],
        );
        foreach ($rows as &$row) {
            $row['public_id'] = Uuid::fromBinary((string) $row['public_id'])->toRfc4122();
            $row['id'] = (int) $row['id'];
            $row['sort_order'] = (int) $row['sort_order'];
        }
        unset($row);
        return $rows;
    }



    /** @return list<array<string,mixed>> */
    public function variantsForEdit(int $productId, int $storeId, int $marketId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT v.id,v.public_id,v.sku,v.gtin,v.mpn,v.status,v.manage_inventory,v.allow_backorder,v.sort_order,v.sale_unit_code,v.quantity_step,v.min_order_quantity,v.max_order_quantity,v.weight_kg,v.length_mm,v.width_mm,v.height_mm,
                    pr.amount_minor,pr.currency,
                    COALESCE((SELECT sl.stocked_quantity FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_market_inventory_location mil ON mil.location_id=sl.location_id AND mil.market_id=? WHERE vii.variant_id=v.id ORDER BY mil.priority,sl.location_id LIMIT 1),'0.000000') stock_quantity
             FROM mc_product_variant v
             JOIN mc_product p ON p.id=v.product_id
             JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=?
             LEFT JOIN mc_price pr ON pr.id=(SELECT px.id FROM mc_price px WHERE px.variant_id=v.id AND px.store_id=? AND px.market_id=? AND px.customer_group='default' AND px.price_list_id IS NULL AND px.max_quantity IS NULL AND px.starts_at IS NULL AND px.ends_at IS NULL ORDER BY px.priority,px.id DESC LIMIT 1)
             WHERE v.product_id=? ORDER BY v.sort_order,v.id",
            [$marketId, $storeId, $storeId, $marketId, $productId],
        );
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['public_id'] = Uuid::fromBinary((string) $row['public_id'])->toRfc4122();
            $row['sort_order'] = (int) $row['sort_order'];
            $row['manage_inventory'] = (bool) $row['manage_inventory'];
            $row['allow_backorder'] = (bool) $row['allow_backorder'];
            $row['price_input'] = $row['amount_minor'] === null ? '0.00' : number_format(((int) $row['amount_minor']) / 100, 2, '.', '');
        }
        unset($row);
        return $rows;
    }

    /** @return list<array{id:int,code:string,data_type:string,name:string,unit_label:?string,value:string}> */
    public function productAttributesForEdit(int $productId, string $locale): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT ad.id,ad.code,ad.data_type,COALESCE(at.name,ad.code) name,at.unit_label,
                    COALESCE(pav.value_text,CAST(pav.value_decimal AS CHAR),IF(pav.value_boolean=1,'1',IF(pav.value_boolean=0,'0',''))) value
             FROM mc_attribute_definition ad
             LEFT JOIN mc_attribute_translation at ON at.attribute_id=ad.id AND at.locale=?
             LEFT JOIN mc_product_attribute_value pav ON pav.id=(SELECT p2.id FROM mc_product_attribute_value p2 WHERE p2.attribute_id=ad.id AND p2.product_id=? AND p2.variant_id IS NULL AND (p2.locale=? OR p2.locale IS NULL) ORDER BY (p2.locale=?) DESC,p2.id DESC LIMIT 1)
             ORDER BY ad.sort_order,ad.id",
            [$locale, $productId, $locale, $locale],
        );
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'data_type' => (string) $row['data_type'],
            'name' => (string) $row['name'],
            'unit_label' => $row['unit_label'] === null ? null : (string) $row['unit_label'],
            'value' => (string) ($row['value'] ?? ''),
        ], $rows);
    }

    /** @return list<array<string,mixed>> */
    public function productDocumentsForEdit(int $productId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT pd.id,pd.public_id,pd.locale,pd.document_type,pd.title,pd.sort_order,pd.visible,ma.storage_key,ma.mime_type,ma.bytes FROM mc_product_document pd JOIN mc_media_asset ma ON ma.id=pd.media_id WHERE pd.product_id=? ORDER BY pd.sort_order,pd.id',
            [$productId],
        );
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['public_id'] = Uuid::fromBinary((string) $row['public_id'])->toRfc4122();
            $row['sort_order'] = (int) $row['sort_order'];
            $row['visible'] = (bool) $row['visible'];
            $row['bytes'] = (int) $row['bytes'];
            $row['url'] = '/media/' . ltrim((string) $row['storage_key'], '/');
        }
        unset($row);
        return $rows;
    }

    /** @return list<array{code:string,name:string}> */
    public function storeLocales(int $storeId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT l.code,COALESCE(NULLIF(l.native_name,\'\'),NULLIF(l.name,\'\'),l.code) name FROM mc_store_locale sl JOIN mc_locale l ON l.code=sl.locale_code WHERE sl.store_id=? AND sl.enabled=1 ORDER BY sl.sort_order,l.code',
            [$storeId],
        );
        return array_map(static fn (array $row): array => ['code' => (string) $row['code'], 'name' => (string) $row['name']], $rows);
    }

    /** @return array<string,mixed> */
    public function categoryForEdit(int $storeId, string $locale, string $publicId): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT c.id,c.public_id,c.parent_id,c.status,c.sort_order,ct.name,sr.slug FROM mc_category c JOIN mc_store_category sc ON sc.category_id=c.id AND sc.store_id=? JOIN mc_category_translation ct ON ct.category_id=c.id AND ct.store_id=? AND ct.locale=? LEFT JOIN mc_seo_route sr ON sr.store_id=? AND sr.locale=? AND sr.entity_type='category' AND sr.entity_public_id=c.public_id WHERE c.public_id=? LIMIT 1",
            [$storeId, $storeId, $locale, $storeId, $locale, Uuid::fromString($publicId)->toBinary()],
        );
        if (!is_array($row)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.76a3f4c42869'));
        }
        $row['public_id'] = Uuid::fromBinary((string) $row['public_id'])->toRfc4122();
        return $row;
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int} */
    public function categories(int $storeId, string $locale, int $page = 1, int $limit = 25, string $search = ''): array
    {
        $page = max(1, $page); $limit = min(100, max(1, $limit)); $offset = ($page - 1) * $limit;
        $where = 'sc.store_id = ? AND ct.locale = ?'; $params = [$storeId, $locale];
        if (trim($search) !== '') { $where .= ' AND ct.name LIKE ?'; $params[] = '%' . trim($search) . '%'; }
        $total = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM mc_category c JOIN mc_store_category sc ON sc.category_id=c.id JOIN mc_category_translation ct ON ct.category_id=c.id AND ct.store_id=sc.store_id WHERE {$where}", $params);
        $rows = $this->connection->fetchAllAssociative("SELECT c.id,c.public_id,c.parent_id,c.status,c.sort_order,ct.name FROM mc_category c JOIN mc_store_category sc ON sc.category_id=c.id JOIN mc_category_translation ct ON ct.category_id=c.id AND ct.store_id=sc.store_id WHERE {$where} ORDER BY c.sort_order,c.id LIMIT {$limit} OFFSET {$offset}", $params);
        foreach ($rows as &$row) { $row['public_id'] = Uuid::fromBinary((string) $row['public_id'])->toRfc4122(); }
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }
}
