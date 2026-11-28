<?php

declare(strict_types=1);

// Synthetic, single-variant catalog: never run against a merchant database.
$url = parse_url((string) getenv('DATABASE_URL'));
$target = (int) ($argv[1] ?? 0);
if (getenv('CI') !== '1' || getenv('NEXORA_DISPOSABLE_SCALE_DB') !== '1' || !is_array($url) || ($url['host'] ?? '') !== '127.0.0.1' || ($url['path'] ?? '') !== '/nexora_e2e' || !in_array($target, [10000, 100000, 500000], true)) {
    throw new RuntimeException('Only an explicitly authorized disposable CI database and a known fixture size are allowed.');
}
$db = new PDO(sprintf('mysql:host=127.0.0.1;port=%d;dbname=nexora_e2e;charset=utf8mb4', $url['port'] ?? 3306), urldecode($url['user'] ?? ''), urldecode($url['pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("SET time_zone='+00:00'");
$initial = (int) $db->query('SELECT COUNT(*) FROM mc_product')->fetchColumn();
if ($initial < 1 || $initial > 1000) {
    throw new RuntimeException('Fresh demo fixture required');
}
$sample = $db->query("SELECT p.id AS product_id,sp.store_id,mp.market_id,pt.locale,v.id AS variant_id,pr.currency,pc.category_id FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.status='active' JOIN mc_market_product mp ON mp.product_id=p.id AND mp.status='active' JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=sp.store_id JOIN mc_product_variant v ON v.product_id=p.id AND v.sort_order=0 AND v.status='active' JOIN mc_price pr ON pr.variant_id=v.id AND pr.store_id=sp.store_id AND pr.market_id=mp.market_id AND pr.customer_group='default' JOIN mc_product_category pc ON pc.product_id=p.id WHERE p.status='published' ORDER BY p.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$sample) {
    throw new RuntimeException('Published sample product required');
}
$store = (int) $sample['store_id'];
$market = (int) $sample['market_id'];
$category = (int) $sample['category_id'];
$sourceProduct = (int) $sample['product_id'];
$sourceVariant = (int) $sample['variant_id'];
$locale = $db->quote((string) $sample['locale']);
$currency = $db->quote((string) $sample['currency']);
$productBase = (int) $db->query('SELECT MAX(id) FROM mc_product')->fetchColumn();
$variantBase = (int) $db->query('SELECT MAX(id) FROM mc_product_variant')->fetchColumn();
$remaining = $target - $initial;
$db->exec('CREATE TEMPORARY TABLE scale_numbers (n INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
$started = hrtime(true);
for ($offset = 0; $offset < $remaining; $offset += 1000) {
    $end = min($remaining, $offset + 1000);
    $numbers = implode(',', array_map(static fn (int $n): string => '(' . $n . ')', range($offset + 1, $end)));
    $db->beginTransaction();
    try {
        $db->exec('DELETE FROM scale_numbers');
        $db->exec('INSERT INTO scale_numbers (n) VALUES ' . $numbers);
        $db->exec("INSERT INTO mc_product (id,public_id,product_type,status,brand_id,tax_class_id,condition_code,created_at,updated_at,row_version) SELECT $productBase+n,UUID_TO_BIN(UUID()),'digital','published',p.brand_id,p.tax_class_id,'new',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),1 FROM scale_numbers CROSS JOIN mc_product p WHERE p.id=$sourceProduct");
        $db->exec("INSERT INTO mc_store_product (store_id,product_id,status,published_at) SELECT $store,$productBase+n,'active',UTC_TIMESTAMP(6) FROM scale_numbers");
        $db->exec("INSERT INTO mc_market_product (market_id,product_id,status,published_at) SELECT $market,$productBase+n,'active',UTC_TIMESTAMP(6) FROM scale_numbers");
        $db->exec("INSERT INTO mc_product_translation (product_id,store_id,locale,name,slug,short_description,created_at,updated_at) SELECT $productBase+n,$store,$locale,CONCAT('Scaleitem ',n),CONCAT('scaleitem-',n),'Synthetic CI product',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6) FROM scale_numbers");
        $db->exec("INSERT INTO mc_product_variant (id,public_id,product_id,sku,status,manage_inventory,allow_backorder,sort_order,sale_unit_code,quantity_step,min_order_quantity,created_at,updated_at,row_version) SELECT $variantBase+n,UUID_TO_BIN(UUID()),$productBase+n,CONCAT('SCALE-CI-',n),'active',0,0,0,v.sale_unit_code,v.quantity_step,v.min_order_quantity,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),1 FROM scale_numbers CROSS JOIN mc_product_variant v WHERE v.id=$sourceVariant");
        $db->exec("INSERT INTO mc_price (variant_id,store_id,market_id,currency,customer_group,min_quantity,amount_minor,tax_included,priority,created_at,updated_at) SELECT $variantBase+n,$store,$market,$currency,'default',1,1000+MOD(n,100000),1,100,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6) FROM scale_numbers");
        $db->exec("INSERT INTO mc_product_category (product_id,category_id,is_primary,sort_order) SELECT $productBase+n,$category,1,n FROM scale_numbers");
        $db->exec("INSERT INTO mc_seo_route (public_id,store_id,locale,entity_type,entity_public_id,slug,path,path_hash,indexable,created_at,updated_at) SELECT UUID_TO_BIN(UUID()),$store,$locale,'product',p.public_id,CONCAT('scaleitem-',n),CONCAT('scaleitem-',n),UNHEX(SHA2(CONCAT('scaleitem-',n),256)),1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6) FROM scale_numbers JOIN mc_product p ON p.id=$productBase+n");
        $db->exec("INSERT INTO mc_search_document (store_id,locale,product_id,document,updated_at) SELECT $store,$locale,$productBase+n,CONCAT('scaleitem synthetic ci ',n),UTC_TIMESTAMP(6) FROM scale_numbers");
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}
$count = (int) $db->query('SELECT COUNT(*) FROM mc_product')->fetchColumn();
$indexed = (int) $db->query("SELECT COUNT(*) FROM mc_search_document WHERE store_id=$store AND locale=$locale AND product_id>$productBase")->fetchColumn();
if ($count !== $target || $indexed !== $remaining) {
    throw new RuntimeException('Incomplete fixture');
}
echo json_encode(['target'=>$target,'products'=>$count,'synthetic'=>$remaining,'indexed'=>$indexed,'category_id'=>$category,'elapsed_ms'=>(hrtime(true)-$started)/1e6,'fixture'=>'single locale, single category, digital products, one variant, no additional images or inventory'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
