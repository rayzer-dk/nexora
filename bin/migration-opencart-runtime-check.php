#!/usr/bin/env php
<?php

declare(strict_types=1);

use Commerce\Modules\Migration\Application\MigrationDryRunAnalyzer;
use Commerce\Modules\Migration\Domain\MigrationEntityType;
use Commerce\Modules\Migration\Source\OpenCart\OpenCart3CatalogSource;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$url = getenv('DATABASE_URL') ?: '';
$parts = parse_url($url);
if ($url === '' || !is_array($parts) || !isset($parts['host'], $parts['path'])) {
    fwrite(STDERR, "Migration OpenCart runtime check requires DATABASE_URL.\n");
    exit(2);
}
$dbName = ltrim((string)$parts['path'], '/');
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $parts['host'], (int)($parts['port'] ?? 3306), $dbName);
$pdo = new PDO($dsn, urldecode((string)($parts['user'] ?? '')), urldecode((string)($parts['pass'] ?? '')), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$p = 'qaoc_';
$tables = ['order_total','order_product','order','customer','product_discount','product_special','product_option_value','option_value_description','product_option','option_description','option','product_attribute','attribute_description','attribute','product_image','product_to_category','product_description','product','category_description','category','seo_url','manufacturer','currency','language'];
$drop = static function () use ($pdo, $p, $tables): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $t) $pdo->exec('DROP TABLE IF EXISTS ' . ($t === 'order' ? '`'.$p.'order`' : '`'.$p.$t.'`'));
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
};

$drop();
$currentStage = 'schema';
try {
    foreach ([
        "CREATE TABLE {$p}language(language_id INT PRIMARY KEY,name VARCHAR(64),code VARCHAR(32),locale VARCHAR(255),status TINYINT,sort_order INT)",
        "CREATE TABLE {$p}currency(currency_id INT PRIMARY KEY,title VARCHAR(64),code VARCHAR(8),symbol_left VARCHAR(16),symbol_right VARCHAR(16),decimal_place INT,value DECIMAL(15,8),status TINYINT,date_modified DATETIME)",
        "CREATE TABLE {$p}manufacturer(manufacturer_id INT PRIMARY KEY,name VARCHAR(64),image VARCHAR(255),sort_order INT)",
        "CREATE TABLE {$p}seo_url(seo_url_id INT AUTO_INCREMENT PRIMARY KEY,store_id INT,language_id INT NULL,query VARCHAR(255),keyword VARCHAR(255))",
        "CREATE TABLE {$p}category(category_id INT PRIMARY KEY,parent_id INT,image VARCHAR(255),sort_order INT,status TINYINT,date_added DATETIME,date_modified DATETIME)",
        "CREATE TABLE {$p}category_description(category_id INT,language_id INT,name VARCHAR(255),description TEXT,meta_title VARCHAR(255),meta_description VARCHAR(255),meta_keyword VARCHAR(255),PRIMARY KEY(category_id,language_id))",
        "CREATE TABLE {$p}product(product_id INT PRIMARY KEY,model VARCHAR(64),sku VARCHAR(64),upc VARCHAR(32),ean VARCHAR(32),jan VARCHAR(32),isbn VARCHAR(32),mpn VARCHAR(64),quantity INT,image VARCHAR(255),manufacturer_id INT,price DECIMAL(15,4),weight DECIMAL(15,4),status TINYINT,sort_order INT,date_added DATETIME,date_modified DATETIME)",
        "CREATE TABLE {$p}product_description(product_id INT,language_id INT,name VARCHAR(255),description TEXT,tag VARCHAR(255),meta_title VARCHAR(255),meta_description VARCHAR(255),meta_keyword VARCHAR(255),PRIMARY KEY(product_id,language_id))",
        "CREATE TABLE {$p}product_to_category(product_id INT,category_id INT,PRIMARY KEY(product_id,category_id))",
        "CREATE TABLE {$p}product_image(product_image_id INT AUTO_INCREMENT PRIMARY KEY,product_id INT,image VARCHAR(255),sort_order INT)",
        "CREATE TABLE {$p}attribute(attribute_id INT PRIMARY KEY,attribute_group_id INT,sort_order INT)",
        "CREATE TABLE {$p}attribute_description(attribute_id INT,language_id INT,name VARCHAR(255),PRIMARY KEY(attribute_id,language_id))",
        "CREATE TABLE {$p}product_attribute(product_id INT,attribute_id INT,language_id INT,text TEXT,PRIMARY KEY(product_id,attribute_id,language_id))",
        "CREATE TABLE {$p}option(option_id INT PRIMARY KEY,type VARCHAR(32),sort_order INT)",
        "CREATE TABLE {$p}option_description(option_id INT,language_id INT,name VARCHAR(255),PRIMARY KEY(option_id,language_id))",
        "CREATE TABLE {$p}product_option(product_option_id INT PRIMARY KEY,product_id INT,option_id INT,value TEXT,required TINYINT)",
        "CREATE TABLE {$p}option_value_description(option_value_id INT,language_id INT,name VARCHAR(255),PRIMARY KEY(option_value_id,language_id))",
        "CREATE TABLE {$p}product_option_value(product_option_value_id INT PRIMARY KEY,product_option_id INT,product_id INT,option_id INT,option_value_id INT,quantity INT,subtract TINYINT,price DECIMAL(15,4),price_prefix VARCHAR(1),points INT,points_prefix VARCHAR(1),weight DECIMAL(15,4),weight_prefix VARCHAR(1),sort_order INT)",
        "CREATE TABLE {$p}product_special(product_special_id INT PRIMARY KEY,product_id INT,customer_group_id INT,priority INT,price DECIMAL(15,4),date_start DATE,date_end DATE)",
        "CREATE TABLE {$p}product_discount(product_discount_id INT PRIMARY KEY,product_id INT,customer_group_id INT,quantity INT,priority INT,price DECIMAL(15,4),date_start DATE,date_end DATE)",
        "CREATE TABLE {$p}customer(customer_id INT PRIMARY KEY,firstname VARCHAR(64),lastname VARCHAR(64),email VARCHAR(255),telephone VARCHAR(64),status TINYINT,date_added DATETIME)",
        "CREATE TABLE `{$p}order`(order_id INT PRIMARY KEY,customer_id INT,firstname VARCHAR(64),lastname VARCHAR(64),email VARCHAR(255),telephone VARCHAR(64),payment_method VARCHAR(128),payment_code VARCHAR(64),shipping_method VARCHAR(128),shipping_code VARCHAR(64),currency_code VARCHAR(8),currency_value DECIMAL(15,8),total DECIMAL(15,4),order_status_id INT,date_added DATETIME,date_modified DATETIME,payment_firstname VARCHAR(64),payment_lastname VARCHAR(64),payment_company VARCHAR(128),payment_address_1 VARCHAR(255),payment_address_2 VARCHAR(255),payment_city VARCHAR(128),payment_postcode VARCHAR(32),payment_country VARCHAR(128),payment_iso_code_2 VARCHAR(8),shipping_firstname VARCHAR(64),shipping_lastname VARCHAR(64),shipping_company VARCHAR(128),shipping_address_1 VARCHAR(255),shipping_address_2 VARCHAR(255),shipping_city VARCHAR(128),shipping_postcode VARCHAR(32),shipping_country VARCHAR(128),shipping_iso_code_2 VARCHAR(8))",
        "CREATE TABLE {$p}order_product(order_product_id INT PRIMARY KEY,order_id INT,product_id INT,name VARCHAR(255),model VARCHAR(64),quantity INT,price DECIMAL(15,4),total DECIMAL(15,4),tax DECIMAL(15,4))",
        "CREATE TABLE {$p}order_total(order_total_id INT PRIMARY KEY,order_id INT,code VARCHAR(64),title VARCHAR(255),value DECIMAL(15,4),sort_order INT)"
    ] as $sql) $pdo->exec($sql . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec("INSERT INTO {$p}language VALUES(1,'Українська','uk-ua','uk_UA.UTF-8',1,1),(2,'English','en-gb','en_GB.UTF-8',1,2)");
    $pdo->exec("INSERT INTO {$p}currency VALUES(1,'Гривня','UAH','₴','',2,1,1,NOW())");
    $pdo->exec("INSERT INTO {$p}manufacturer VALUES(10,'Nexora Test','brand.png',0)");
    $pdo->exec("INSERT INTO {$p}category VALUES(20,0,'category.jpg',0,1,NOW(),NOW())");
    $pdo->exec("INSERT INTO {$p}category_description VALUES(20,1,'Категорія','Опис','Категорія','Опис','тест'),(20,2,'Category','Description','Category','Description','test')");
    $pdo->exec("INSERT INTO {$p}attribute VALUES(30,1,0)");
    $pdo->exec("INSERT INTO {$p}attribute_description VALUES(30,1,'Потужність'),(30,2,'Power')");
    $pdo->exec("INSERT INTO {$p}product VALUES(40,'MODEL-40','SKU-40','','4820000000040','','','MPN-40',15,'product.jpg',10,1250.50,2.5,1,0,NOW(),NOW())");
    $pdo->exec("INSERT INTO {$p}product_description VALUES(40,1,'Тестовий товар','Опис','насос','Товар','Опис','насос'),(40,2,'Test product','Description','pump','Product','Description','pump')");
    $pdo->exec("INSERT INTO {$p}product_to_category VALUES(40,20)");
    $pdo->exec("INSERT INTO {$p}product_image(product_id,image,sort_order) VALUES(40,'product-2.jpg',1)");
    $pdo->exec("INSERT INTO {$p}product_attribute VALUES(40,30,1,'1200 Вт'),(40,30,2,'1200 W')");
    $pdo->exec("INSERT INTO {$p}option VALUES(50,'select',0)");
    $pdo->exec("INSERT INTO {$p}option_description VALUES(50,1,'Колір'),(50,2,'Color')");
    $pdo->exec("INSERT INTO {$p}product_option VALUES(60,40,50,'',1)");
    $pdo->exec("INSERT INTO {$p}option_value_description VALUES(70,1,'Червоний'),(70,2,'Red')");
    $pdo->exec("INSERT INTO {$p}product_option_value VALUES(80,60,40,50,70,5,1,100,'+',0,'+',0,'+',0)");
    $pdo->exec("INSERT INTO {$p}product_special VALUES(90,40,1,1,1100,'2026-01-01','2026-12-31')");
    $pdo->exec("INSERT INTO {$p}product_discount VALUES(91,40,1,10,1,1000,'2026-01-01','2026-12-31')");
    $pdo->exec("INSERT INTO {$p}customer VALUES(100,'Іван','Тест','test@example.com','+380501112233',1,NOW())");
    $pdo->exec("INSERT INTO `{$p}order` VALUES(110,100,'Іван','Тест','test@example.com','+380501112233','Card','card','Nova Poshta','novaposhta','UAH',1,1350.50,5,NOW(),NOW(),'Іван','Тест','','Вулиця 1','','Київ','01001','Україна','UA','Іван','Тест','','Вулиця 1','','Київ','01001','Україна','UA')");
    $pdo->exec("INSERT INTO {$p}order_product VALUES(120,110,40,'Тестовий товар','MODEL-40',1,1250.50,1250.50,0)");
    $pdo->exec("INSERT INTO {$p}order_total VALUES(130,110,'sub_total','Subtotal',1250.50,1),(131,110,'shipping','Shipping',100,2),(132,110,'total','Total',1350.50,9)");
    $pdo->exec("INSERT INTO {$p}seo_url(store_id,language_id,query,keyword) VALUES(0,1,'manufacturer_id=10','nexora-test'),(0,1,'category_id=20','testova-kategoriia'),(0,2,'category_id=20','test-category'),(0,1,'product_id=40','testovyi-tovar'),(0,2,'product_id=40','test-product')");

    $source = new OpenCart3CatalogSource($pdo, $p);
    $records = [];
    foreach ($source->supportedEntities() as $type) {
        $currentStage = 'read:' . $type->value;
        $batch = $source->read($type, null, 250);
        if (!$batch->complete || count($batch->records) < 1) throw new RuntimeException('Missing fixture entity: ' . $type->value);
        $records[$type->value] = $batch->records;
    }
    $product = $records[MigrationEntityType::Product->value][0]->data;
    if (($product['sku'] ?? '') !== 'SKU-40' || ($product['gtin'] ?? '') !== '4820000000040') throw new RuntimeException('Product identifiers mismatch.');
    if (count((array)$product['images']) !== 1 || count((array)$product['options']) !== 1 || count((array)$product['options'][0]['values']) !== 1) throw new RuntimeException('Images/options mismatch.');
    if (count((array)$product['specials']) !== 1 || count((array)$product['discounts']) !== 1 || count((array)$product['seo_urls']) !== 2) throw new RuntimeException('Pricing/SEO migration mismatch.');
    if (!isset($product['translations']['uk-UA'], $product['translations']['en-GB'])) throw new RuntimeException('Multilingual migration mismatch.');
    $order = $records[MigrationEntityType::Order->value][0]->data;
    if (($order['customer_source_key'] ?? '') !== '100' || count((array)$order['items']) !== 1 || count((array)$order['totals']) !== 3) throw new RuntimeException('Customer/order migration mismatch.');

    $currentStage = 'dry-run';
    $report = (new MigrationDryRunAnalyzer())->analyze($source, 100);
    $errors = array_filter($report->issues, static fn($i): bool => $i->severity === 'error');
    if ($errors !== []) throw new RuntimeException('Dry-run validation produced errors.');

    echo "OpenCart/ocStore migration runtime: PASSED\n";
    echo "multilingual=yes images=yes options=yes discounts=yes seo=yes customers=yes orders=yes dry_run_errors=0\n";
} catch (Throwable $e) {
    fwrite(STDERR, "OpenCart/ocStore migration runtime FAILED at {$currentStage}: {$e->getMessage()}\n");
    exit(1);
} finally {
    $drop();
}
