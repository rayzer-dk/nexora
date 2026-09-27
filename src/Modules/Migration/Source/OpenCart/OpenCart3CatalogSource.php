<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Source\OpenCart;

use Commerce\Modules\Localization\Domain\LocaleNormalizer;
use Commerce\Modules\Migration\Contract\MigrationSourceInterface;
use Commerce\Modules\Migration\Domain\MigrationBatch;
use Commerce\Modules\Migration\Domain\MigrationEntityType;
use Commerce\Modules\Migration\Domain\MigrationRecord;
use PDO;
use RuntimeException;

final class OpenCart3CatalogSource implements MigrationSourceInterface
{
    private readonly string $prefix;

    public function __construct(
        private readonly PDO $pdo,
        string $tablePrefix = 'oc_',
        private readonly LocaleNormalizer $localeNormalizer = new LocaleNormalizer(),
    ) {
        if (preg_match('/^[A-Za-z0-9_]*$/', $tablePrefix) !== 1) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.bebce0fe588d'));
        }
        $this->prefix = $tablePrefix;
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function code(): string
    {
        return 'opencart3';
    }

    public function label(): string
    {
        return 'OpenCart / ocStore 3.x catalog';
    }

    public function supportedEntities(): array
    {
        return [
            MigrationEntityType::Locale,
            MigrationEntityType::Currency,
            MigrationEntityType::Brand,
            MigrationEntityType::Category,
            MigrationEntityType::Attribute,
            MigrationEntityType::Product,
            MigrationEntityType::Customer,
            MigrationEntityType::Order,
        ];
    }

    public function read(MigrationEntityType $type, ?string $cursor, int $limit = 250): MigrationBatch
    {
        $limit = max(1, min(1000, $limit));
        $after = max(0, (int) ($cursor ?? '0'));

        return match ($type) {
            MigrationEntityType::Locale => $this->readLocales($after, $limit),
            MigrationEntityType::Currency => $this->readCurrencies($after, $limit),
            MigrationEntityType::Brand => $this->readBrands($after, $limit),
            MigrationEntityType::Category => $this->readCategories($after, $limit),
            MigrationEntityType::Attribute => $this->readAttributes($after, $limit),
            MigrationEntityType::Product => $this->readProducts($after, $limit),
            MigrationEntityType::Customer => $this->readCustomers($after, $limit),
            MigrationEntityType::Order => $this->readOrders($after, $limit),
            default => new MigrationBatch([], null, true),
        };
    }

    private function readLocales(int $after, int $limit): MigrationBatch
    {
        $rows = $this->queryRows(sprintf(
            'SELECT language_id, name, code, locale, status, sort_order FROM %slanguage WHERE language_id > %d ORDER BY language_id ASC LIMIT %d',
            $this->prefix,
            $after,
            $limit,
        ));
        $records = [];
        foreach ($rows as $row) {
            $code = $this->localeNormalizer->normalize((string) $row['code']);
            $records[] = new MigrationRecord(MigrationEntityType::Locale, (string) $row['language_id'], [
                'code' => $code,
                'name' => (string) $row['name'],
                'legacy_locale' => (string) ($row['locale'] ?? ''),
                'enabled' => (bool) $row['status'],
                'sort_order' => (int) $row['sort_order'],
            ]);
        }
        return $this->batch($records, $rows, 'language_id', $limit);
    }

    private function readCurrencies(int $after, int $limit): MigrationBatch
    {
        $rows = $this->queryRows(sprintf(
            'SELECT currency_id, title, code, symbol_left, symbol_right, decimal_place, value, status, date_modified FROM %scurrency WHERE currency_id > %d ORDER BY currency_id ASC LIMIT %d',
            $this->prefix,
            $after,
            $limit,
        ));
        $records = [];
        foreach ($rows as $row) {
            $records[] = new MigrationRecord(MigrationEntityType::Currency, (string) $row['currency_id'], [
                'code' => strtoupper((string) $row['code']),
                'name' => (string) $row['title'],
                'symbol' => trim((string) ($row['symbol_left'] ?: $row['symbol_right'])),
                'minor_units' => (int) $row['decimal_place'],
                'legacy_rate' => (string) $row['value'],
                'enabled' => (bool) $row['status'],
            ]);
        }
        return $this->batch($records, $rows, 'currency_id', $limit);
    }

    private function readBrands(int $after, int $limit): MigrationBatch
    {
        $rows = $this->queryRows(sprintf(
            'SELECT manufacturer_id, name, image, sort_order FROM %smanufacturer WHERE manufacturer_id > %d ORDER BY manufacturer_id ASC LIMIT %d',
            $this->prefix,
            $after,
            $limit,
        ));
        $seo = [];
        foreach ($this->readEntitySeoRows('manufacturer_id', array_map(static fn(array $row): int => (int)$row['manufacturer_id'], $rows)) as $seoRow) { $seo[(int)$seoRow['entity_id']][] = $seoRow; }
        $records = [];
        foreach ($rows as $row) {
            $id=(int)$row['manufacturer_id'];
            $records[] = new MigrationRecord(MigrationEntityType::Brand, (string) $id, [
                'name' => (string) $row['name'],
                'image' => $row['image'] !== '' ? (string) $row['image'] : null,
                'sort_order' => (int) $row['sort_order'],
                'seo_urls' => $seo[$id] ?? [],
            ]);
        }
        return $this->batch($records, $rows, 'manufacturer_id', $limit);
    }

    private function readCategories(int $after, int $limit): MigrationBatch
    {
        $ids = $this->fetchIds('category', 'category_id', $after, $limit);
        if ($ids === []) {
            return new MigrationBatch([], null, true);
        }
        $in = implode(',', array_map('intval', $ids));
        $baseRows = $this->queryRows("SELECT category_id, parent_id, image, sort_order, status, date_added, date_modified FROM {$this->prefix}category WHERE category_id IN ({$in}) ORDER BY category_id");
        $descriptionRows = $this->queryRows("SELECT cd.category_id, cd.name, cd.description, cd.meta_title, cd.meta_description, cd.meta_keyword, l.code AS language_code FROM {$this->prefix}category_description cd INNER JOIN {$this->prefix}language l ON l.language_id = cd.language_id WHERE cd.category_id IN ({$in}) ORDER BY cd.category_id, cd.language_id");
        $translations = [];
        foreach ($descriptionRows as $row) {
            $translations[(int) $row['category_id']][$this->localeNormalizer->normalize((string) $row['language_code'])] = [
                'name' => (string) $row['name'],
                'description' => (string) $row['description'],
                'meta_title' => (string) $row['meta_title'],
                'meta_description' => (string) $row['meta_description'],
                'meta_keywords' => (string) $row['meta_keyword'],
            ];
        }
        $seo = [];
        foreach ($this->readEntitySeoRows('category_id', $ids) as $seoRow) { $seo[(int)$seoRow['entity_id']][] = $seoRow; }
        $records = [];
        foreach ($baseRows as $row) {
            $id = (int) $row['category_id'];
            $records[] = new MigrationRecord(MigrationEntityType::Category, (string) $id, [
                'parent_source_key' => (int) $row['parent_id'] > 0 ? (string) $row['parent_id'] : null,
                'image' => $row['image'] !== '' ? (string) $row['image'] : null,
                'sort_order' => (int) $row['sort_order'],
                'enabled' => (bool) $row['status'],
                'translations' => $translations[$id] ?? [],
                'seo_urls' => $seo[$id] ?? [],
            ]);
        }
        return new MigrationBatch($records, (string) end($ids), count($ids) < $limit);
    }

    private function readProducts(int $after, int $limit): MigrationBatch
    {
        $ids = $this->fetchIds('product', 'product_id', $after, $limit);
        if ($ids === []) {
            return new MigrationBatch([], null, true);
        }
        $in = implode(',', array_map('intval', $ids));
        $baseRows = $this->queryRows("SELECT p.product_id, p.model, p.sku, p.upc, p.ean, p.jan, p.isbn, p.mpn, p.quantity, p.image, p.manufacturer_id, p.price, p.weight, p.status, p.sort_order, p.date_added, p.date_modified, m.name AS manufacturer_name FROM {$this->prefix}product p LEFT JOIN {$this->prefix}manufacturer m ON m.manufacturer_id = p.manufacturer_id WHERE p.product_id IN ({$in}) ORDER BY p.product_id");
        $descriptionRows = $this->queryRows("SELECT pd.product_id, pd.name, pd.description, pd.tag, pd.meta_title, pd.meta_description, pd.meta_keyword, l.code AS language_code FROM {$this->prefix}product_description pd INNER JOIN {$this->prefix}language l ON l.language_id = pd.language_id WHERE pd.product_id IN ({$in}) ORDER BY pd.product_id, pd.language_id");
        $categoryRows = $this->queryRows("SELECT product_id, category_id FROM {$this->prefix}product_to_category WHERE product_id IN ({$in}) ORDER BY product_id, category_id");
        $imageRows = $this->queryRows("SELECT product_id, image, sort_order FROM {$this->prefix}product_image WHERE product_id IN ({$in}) ORDER BY product_id, sort_order, product_image_id");
        $attributeRows = $this->tableExists('product_attribute') ? $this->queryRows("SELECT pa.product_id,pa.attribute_id,pa.text,l.code AS language_code FROM {$this->prefix}product_attribute pa INNER JOIN {$this->prefix}language l ON l.language_id=pa.language_id WHERE pa.product_id IN ({$in}) ORDER BY pa.product_id,pa.attribute_id,pa.language_id") : [];
        $optionRows = $this->tableExists('product_option') ? $this->queryRows("SELECT po.product_option_id,po.product_id,po.option_id,po.value,po.required,o.type,od.name,l.code AS language_code FROM {$this->prefix}product_option po LEFT JOIN {$this->prefix}option o ON o.option_id=po.option_id LEFT JOIN {$this->prefix}option_description od ON od.option_id=po.option_id LEFT JOIN {$this->prefix}language l ON l.language_id=od.language_id WHERE po.product_id IN ({$in}) ORDER BY po.product_id,po.product_option_id,od.language_id") : [];
        $optionValueRows = $this->tableExists('product_option_value') ? $this->queryRows("SELECT pov.product_option_value_id,pov.product_option_id,pov.product_id,pov.option_id,pov.option_value_id,pov.quantity,pov.subtract,pov.price,pov.price_prefix,pov.points,pov.points_prefix,pov.weight,pov.weight_prefix,pov.sort_order,ovd.name,l.code AS language_code FROM {$this->prefix}product_option_value pov LEFT JOIN {$this->prefix}option_value_description ovd ON ovd.option_value_id=pov.option_value_id LEFT JOIN {$this->prefix}language l ON l.language_id=ovd.language_id WHERE pov.product_id IN ({$in}) ORDER BY pov.product_id,pov.product_option_id,pov.sort_order,pov.product_option_value_id,ovd.language_id") : [];
        $specialRows = $this->tableExists('product_special') ? $this->queryRows("SELECT product_id,customer_group_id,priority,price,date_start,date_end FROM {$this->prefix}product_special WHERE product_id IN ({$in}) ORDER BY product_id,priority,product_special_id") : [];
        $discountRows = $this->tableExists('product_discount') ? $this->queryRows("SELECT product_id,customer_group_id,quantity,priority,price,date_start,date_end FROM {$this->prefix}product_discount WHERE product_id IN ({$in}) ORDER BY product_id,quantity,priority,product_discount_id") : [];
        $seoRows = $this->readEntitySeoRows('product_id', $ids);
        $translations = $categories = $images = $attributes = $options = $optionValues = $specials = $discounts = $seo = [];
        foreach ($descriptionRows as $row) {
            $translations[(int) $row['product_id']][$this->localeNormalizer->normalize((string) $row['language_code'])] = [
                'name' => (string) $row['name'],
                'description' => (string) $row['description'],
                'tags' => (string) $row['tag'],
                'meta_title' => (string) $row['meta_title'],
                'meta_description' => (string) $row['meta_description'],
                'meta_keywords' => (string) $row['meta_keyword'],
            ];
        }
        foreach ($categoryRows as $row) {
            $categories[(int) $row['product_id']][] = (string) $row['category_id'];
        }
        foreach ($imageRows as $row) {
            if ((string) $row['image'] !== '') {
                $images[(int) $row['product_id']][] = ['path' => (string) $row['image'], 'sort_order' => (int) $row['sort_order']];
            }
        }
        foreach ($attributeRows as $row) {
            $attributes[(int)$row['product_id']][(string)$row['attribute_id']][$this->localeNormalizer->normalize((string)$row['language_code'])] = (string)$row['text'];
        }
        foreach ($optionRows as $row) {
            $pid=(int)$row['product_id']; $key=(string)$row['product_option_id']; $locale=$row['language_code']!==null?$this->localeNormalizer->normalize((string)$row['language_code']):null;
            if (!isset($options[$pid][$key])) $options[$pid][$key]=['product_option_source_key'=>$key,'option_source_key'=>(string)$row['option_id'],'type'=>(string)$row['type'],'required'=>(bool)$row['required'],'value'=>(string)$row['value'],'translations'=>[],'values'=>[]];
            if ($locale!==null && trim((string)$row['name'])!=='') $options[$pid][$key]['translations'][$locale]=(string)$row['name'];
        }
        foreach ($optionValueRows as $row) {
            $pid=(int)$row['product_id']; $key=(string)$row['product_option_id']; $vkey=(string)$row['product_option_value_id']; $locale=$row['language_code']!==null?$this->localeNormalizer->normalize((string)$row['language_code']):null;
            if (!isset($optionValues[$pid][$key][$vkey])) $optionValues[$pid][$key][$vkey]=['source_key'=>$vkey,'option_value_source_key'=>(string)$row['option_value_id'],'quantity'=>(int)$row['quantity'],'subtract'=>(bool)$row['subtract'],'price_decimal'=>(string)$row['price'],'price_prefix'=>(string)$row['price_prefix'],'weight'=>(string)$row['weight'],'weight_prefix'=>(string)$row['weight_prefix'],'sort_order'=>(int)$row['sort_order'],'translations'=>[]];
            if ($locale!==null && trim((string)$row['name'])!=='') $optionValues[$pid][$key][$vkey]['translations'][$locale]=(string)$row['name'];
        }
        foreach ($optionValues as $pid=>$byOption) foreach($byOption as $key=>$values) if(isset($options[$pid][$key])) $options[$pid][$key]['values']=array_values($values);
        foreach ($specialRows as $row) $specials[(int)$row['product_id']][]=['customer_group_id'=>(int)$row['customer_group_id'],'priority'=>(int)$row['priority'],'price_decimal'=>(string)$row['price'],'date_start'=>(string)$row['date_start'],'date_end'=>(string)$row['date_end']];
        foreach ($discountRows as $row) $discounts[(int)$row['product_id']][]=['customer_group_id'=>(int)$row['customer_group_id'],'quantity'=>(int)$row['quantity'],'priority'=>(int)$row['priority'],'price_decimal'=>(string)$row['price'],'date_start'=>(string)$row['date_start'],'date_end'=>(string)$row['date_end']];
        foreach ($seoRows as $row) $seo[(int)$row['entity_id']][]=$row;

        $records = [];
        foreach ($baseRows as $row) {
            $id = (int) $row['product_id'];
            $sku = trim((string) $row['sku']);
            $model = trim((string) $row['model']);
            $records[] = new MigrationRecord(MigrationEntityType::Product, (string) $id, [
                'sku' => $sku !== '' ? $sku : $model,
                'model' => $model,
                'gtin' => $this->firstNonEmpty($row['ean'] ?? null, $row['upc'] ?? null, $row['jan'] ?? null, $row['isbn'] ?? null),
                'mpn' => trim((string) $row['mpn']) ?: null,
                'brand_source_key' => (int) $row['manufacturer_id'] > 0 ? (string) $row['manufacturer_id'] : null,
                'brand_name' => $row['manufacturer_name'] ?: null,
                'quantity' => (int) $row['quantity'],
                'legacy_price_decimal' => (string) $row['price'],
                'weight' => (string) $row['weight'],
                'enabled' => (bool) $row['status'],
                'sort_order' => (int) $row['sort_order'],
                'main_image' => $row['image'] !== '' ? (string) $row['image'] : null,
                'images' => $images[$id] ?? [],
                'category_source_keys' => $categories[$id] ?? [],
                'translations' => $translations[$id] ?? [],
                'attributes' => $attributes[$id] ?? [],
                'options' => array_values($options[$id] ?? []),
                'specials' => $specials[$id] ?? [],
                'discounts' => $discounts[$id] ?? [],
                'seo_urls' => $seo[$id] ?? [],
            ]);
        }
        return new MigrationBatch($records, (string) end($ids), count($ids) < $limit);
    }


    private function readAttributes(int $after, int $limit): MigrationBatch
    {
        $rows = $this->queryRows(sprintf(
            'SELECT a.attribute_id, a.attribute_group_id, a.sort_order FROM %sattribute a WHERE a.attribute_id > %d ORDER BY a.attribute_id ASC LIMIT %d',
            $this->prefix,
            $after,
            $limit,
        ));
        if ($rows === []) {
            return new MigrationBatch([], null, true);
        }
        $ids = array_map(static fn (array $row): int => (int) $row['attribute_id'], $rows);
        $in = implode(',', $ids);
        $names = $this->queryRows("SELECT ad.attribute_id,ad.name,l.code AS language_code FROM {$this->prefix}attribute_description ad INNER JOIN {$this->prefix}language l ON l.language_id=ad.language_id WHERE ad.attribute_id IN ({$in}) ORDER BY ad.attribute_id,ad.language_id");
        $translations = [];
        foreach ($names as $row) {
            $translations[(int) $row['attribute_id']][$this->localeNormalizer->normalize((string) $row['language_code'])] = (string) $row['name'];
        }
        $records = [];
        foreach ($rows as $row) {
            $id = (int) $row['attribute_id'];
            $records[] = new MigrationRecord(MigrationEntityType::Attribute, (string) $id, [
                'group_source_key' => (string) $row['attribute_group_id'],
                'sort_order' => (int) $row['sort_order'],
                'translations' => $translations[$id] ?? [],
            ]);
        }
        return $this->batch($records, $rows, 'attribute_id', $limit);
    }

    private function readCustomers(int $after, int $limit): MigrationBatch
    {
        $rows = $this->queryRows(sprintf(
            'SELECT customer_id,firstname,lastname,email,telephone,status,date_added FROM %scustomer WHERE customer_id > %d ORDER BY customer_id ASC LIMIT %d',
            $this->prefix,
            $after,
            $limit,
        ));
        $records = [];
        foreach ($rows as $row) {
            $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
            $phone = trim((string) ($row['telephone'] ?? ''));
            $records[] = new MigrationRecord(MigrationEntityType::Customer, (string) $row['customer_id'], [
                'display_name' => trim((string) $row['firstname'].' '.(string) $row['lastname']),
                'email' => $email !== '' ? $email : null,
                'phone' => $phone !== '' ? $phone : null,
                'enabled' => (bool) $row['status'],
                'legacy_created_at' => (string) ($row['date_added'] ?? ''),
            ]);
        }
        return $this->batch($records, $rows, 'customer_id', $limit);
    }

    private function readOrders(int $after, int $limit): MigrationBatch
    {
        $rows = $this->queryRows(sprintf(
            'SELECT order_id,customer_id,firstname,lastname,email,telephone,payment_method,payment_code,shipping_method,shipping_code,currency_code,currency_value,total,order_status_id,date_added,date_modified,payment_firstname,payment_lastname,payment_company,payment_address_1,payment_address_2,payment_city,payment_postcode,payment_country,payment_iso_code_2,shipping_firstname,shipping_lastname,shipping_company,shipping_address_1,shipping_address_2,shipping_city,shipping_postcode,shipping_country,shipping_iso_code_2 FROM `%sorder` WHERE order_id > %d ORDER BY order_id ASC LIMIT %d',
            $this->prefix,
            $after,
            $limit,
        ));
        if ($rows === []) {
            return new MigrationBatch([], null, true);
        }
        $ids = array_map(static fn (array $row): int => (int) $row['order_id'], $rows);
        $in = implode(',', $ids);
        $productRows = $this->queryRows("SELECT order_product_id,order_id,product_id,name,model,quantity,price,total,tax FROM {$this->prefix}order_product WHERE order_id IN ({$in}) ORDER BY order_id,order_product_id");
        $totalRows = $this->queryRows("SELECT order_total_id,order_id,code,title,value,sort_order FROM {$this->prefix}order_total WHERE order_id IN ({$in}) ORDER BY order_id,sort_order,order_total_id");
        $products = $totals = [];
        foreach ($productRows as $row) {
            $products[(int)$row['order_id']][] = [
                'product_source_key'=>(string)$row['product_id'], 'name'=>(string)$row['name'], 'sku'=>(string)$row['model'],
                'quantity'=>(int)$row['quantity'], 'unit_price_decimal'=>(string)$row['price'], 'line_total_decimal'=>(string)$row['total'], 'tax_decimal'=>(string)$row['tax'],
            ];
        }
        foreach ($totalRows as $row) {
            $totals[(int)$row['order_id']][] = [
                'code'=>(string)$row['code'],'title'=>(string)$row['title'],'value_decimal'=>(string)$row['value'],'sort_order'=>(int)$row['sort_order'],
            ];
        }
        $records = [];
        foreach ($rows as $row) {
            $id = (int)$row['order_id'];
            $records[] = new MigrationRecord(MigrationEntityType::Order, (string)$id, [
                'customer_source_key'=>(int)$row['customer_id']>0?(string)$row['customer_id']:null,
                'customer_name'=>trim((string)$row['firstname'].' '.(string)$row['lastname']),
                'email'=>mb_strtolower(trim((string)$row['email'])), 'phone'=>trim((string)$row['telephone']),
                'currency'=>strtoupper((string)$row['currency_code']), 'currency_value'=>(string)$row['currency_value'], 'total_decimal'=>(string)$row['total'],
                'legacy_status_id'=>(int)$row['order_status_id'], 'legacy_created_at'=>(string)$row['date_added'], 'legacy_updated_at'=>(string)$row['date_modified'],
                'payment'=>['method'=>(string)$row['payment_method'],'code'=>(string)$row['payment_code']],
                'shipping'=>['method'=>(string)$row['shipping_method'],'code'=>(string)$row['shipping_code']],
                'payment_address'=>['name'=>trim((string)$row['payment_firstname'].' '.(string)$row['payment_lastname']),'company'=>(string)$row['payment_company'],'address_1'=>(string)$row['payment_address_1'],'address_2'=>(string)$row['payment_address_2'],'city'=>(string)$row['payment_city'],'postcode'=>(string)$row['payment_postcode'],'country'=>(string)$row['payment_country'],'country_code'=>(string)$row['payment_iso_code_2']],
                'shipping_address'=>['name'=>trim((string)$row['shipping_firstname'].' '.(string)$row['shipping_lastname']),'company'=>(string)$row['shipping_company'],'address_1'=>(string)$row['shipping_address_1'],'address_2'=>(string)$row['shipping_address_2'],'city'=>(string)$row['shipping_city'],'postcode'=>(string)$row['shipping_postcode'],'country'=>(string)$row['shipping_country'],'country_code'=>(string)$row['shipping_iso_code_2']],
                'items'=>$products[$id]??[], 'totals'=>$totals[$id]??[],
            ]);
        }
        return $this->batch($records, $rows, 'order_id', $limit);
    }


    /** @param list<int> $ids @return list<array{entity_id:int,language_id:int|null,language_code:?string,keyword:string}> */
    private function readEntitySeoRows(string $entityKey, array $ids): array
    {
        if ($ids === [] || !in_array($entityKey, ['product_id','category_id','manufacturer_id'], true)) return [];
        $queries=implode(',',array_map(static fn(int $id): string => "'".$entityKey."=".$id."'",array_map('intval',$ids)));
        if ($this->tableExists('seo_url')) {
            $rows=$this->queryRows("SELECT su.query,su.keyword,su.language_id,l.code AS language_code FROM {$this->prefix}seo_url su LEFT JOIN {$this->prefix}language l ON l.language_id=su.language_id WHERE su.query IN ({$queries}) ORDER BY su.seo_url_id");
            $out=[];
            foreach($rows as $row){ if(preg_match('/^'.preg_quote($entityKey,'/').'=(\\d+)$/',(string)$row['query'],$m)!==1) continue; $out[]=['entity_id'=>(int)$m[1],'language_id'=>$row['language_id']!==null?(int)$row['language_id']:null,'language_code'=>$row['language_code']!==null?$this->localeNormalizer->normalize((string)$row['language_code']):null,'keyword'=>(string)$row['keyword']]; }
            return $out;
        }
        if ($this->tableExists('url_alias')) {
            $rows=$this->queryRows("SELECT query,keyword FROM {$this->prefix}url_alias WHERE query IN ({$queries}) ORDER BY url_alias_id");
            $out=[];
            foreach($rows as $row){ if(preg_match('/^'.preg_quote($entityKey,'/').'=(\\d+)$/',(string)$row['query'],$m)!==1) continue; $out[]=['entity_id'=>(int)$m[1],'language_id'=>null,'language_code'=>null,'keyword'=>(string)$row['keyword']]; }
            return $out;
        }
        return [];
    }

    private function tableExists(string $table): bool
    {
        if (preg_match('/^[A-Za-z0-9_]+$/',$table)!==1) return false;
        $statement=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        if($statement===false) return false;
        $statement->execute([$this->prefix.$table]);
        return (int)$statement->fetchColumn()===1;
    }

    /** @return list<int> */
    private function fetchIds(string $table, string $column, int $after, int $limit): array
    {
        $allowed = ['category' => 'category_id', 'product' => 'product_id'];
        if (($allowed[$table] ?? null) !== $column) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.28455b07e6e9'));
        }
        $rows = $this->queryRows(sprintf('SELECT %s FROM %s%s WHERE %s > %d ORDER BY %s ASC LIMIT %d', $column, $this->prefix, $table, $column, $after, $column, $limit));
        return array_map(static fn (array $row): int => (int) $row[$column], $rows);
    }

    /** @return list<array<string, mixed>> */
    private function queryRows(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2562986efabb'));
        }
        return $statement->fetchAll();
    }

    /** @param list<MigrationRecord> $records @param list<array<string, mixed>> $rows */
    private function batch(array $records, array $rows, string $idColumn, int $limit): MigrationBatch
    {
        if ($rows === []) {
            return new MigrationBatch([], null, true);
        }
        $last = $rows[array_key_last($rows)];
        return new MigrationBatch($records, (string) $last[$idColumn], count($rows) < $limit);
    }

    private function firstNonEmpty(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }
}
