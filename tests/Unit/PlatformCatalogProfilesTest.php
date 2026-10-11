<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Migration\Domain\MigrationEntityType;
use Commerce\Modules\Migration\Source\File\FileCatalogSource;
use Commerce\Modules\Migration\Source\File\PlatformCatalogProfiles;
use PHPUnit\Framework\TestCase;

final class PlatformCatalogProfilesTest extends TestCase
{
    public function testMoneyAcceptsEveryWrittenForm(): void
    {
        self::assertSame('1234.50', PlatformCatalogProfiles::money('1 234,50 грн'));
        self::assertSame('1234.50', PlatformCatalogProfiles::money('1,234.50'));
        self::assertSame('99', PlatformCatalogProfiles::money('99'));
        self::assertSame('1234567.00', PlatformCatalogProfiles::money('1.234.567,00'));
        self::assertSame('', PlatformCatalogProfiles::money('безкоштовно'));
    }

    public function testPlatformsAreRecognisedByTheirColumns(): void
    {
        self::assertSame('shopify', PlatformCatalogProfiles::detect(['Handle', 'Title', 'Body (HTML)', 'Variant SKU', 'Variant Price']));
        self::assertSame('woocommerce', PlatformCatalogProfiles::detect(['ID', 'Type', 'SKU', 'Name', 'Regular price', 'Categories']));
        self::assertSame('prestashop', PlatformCatalogProfiles::detect(['ID', 'Active (0/1)', 'Name *', 'Categories (x,y,z...)', 'Price tax excluded']));
        self::assertSame('prom', PlatformCatalogProfiles::detect(['Код_товара', 'Название_позиции', 'Цена']));
        self::assertSame('prom', PlatformCatalogProfiles::detect(['Код_товару', 'Назва_позиції', 'Ціна']));
        self::assertSame('generic', PlatformCatalogProfiles::detect(['Артикул', 'Назва', 'Ціна']));
    }

    public function testShopifyRowsOfOneHandleBecomeOneProductWithOptionsAndPhotos(): void
    {
        $headers = ['Handle', 'Title', 'Body (HTML)', 'Vendor', 'Product Category', 'Option1 Name', 'Option1 Value', 'Variant SKU', 'Variant Grams', 'Variant Inventory Qty', 'Variant Price', 'Variant Compare At Price', 'Image Src', 'Status'];
        $rows = [
            ['red-mug', 'Red mug', '<p>Nice</p>', 'Acme', 'Home > Kitchen', 'Size', 'S', 'MUG-S', '300', '4', '120.00', '150.00', 'https://cdn.example.com/a.jpg', 'active'],
            ['red-mug', '', '', '', '', '', 'L', 'MUG-L', '400', '6', '140.00', '', 'https://cdn.example.com/b.jpg', ''],
            ['draft-cup', 'Draft cup', '', 'Acme', '', '', '', 'CUP-1', '100', '1', '10', '', '', 'draft'],
        ];
        $result = PlatformCatalogProfiles::normalize('shopify', $headers, $rows);
        self::assertCount(2, $result['products']);
        $mug = $result['products'][0];
        self::assertSame('MUG-S', $mug['sku']);
        self::assertSame('120.00', $mug['price']);
        self::assertSame('150.00', $mug['old_price']);
        self::assertSame(10, $mug['stock']);
        self::assertSame(['https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.jpg'], $mug['images']);
        self::assertSame(['Size' => ['S', 'L']], $mug['options']);
        self::assertSame([['Home', 'Kitchen']], $mug['categories']);
        self::assertSame(['/products/red-mug'], $mug['legacy_paths']);
        self::assertTrue($mug['enabled']);
        self::assertFalse($result['products'][1]['enabled']);
        self::assertSame(['variants_flattened:red-mug'], $result['warnings']);
        $source = new FileCatalogSource('shopify', $result['products'], 'uk-UA', 'k');
        $record = $source->read(MigrationEntityType::Product, null, 10)->records[0];
        self::assertSame('Size', $record->data['options'][0]['translations']['uk-UA']);
        self::assertSame(['S', 'L'], array_map(static fn (array $v): string => $v['translations']['uk-UA'], $record->data['options'][0]['values']));
        self::assertSame('150.00', $record->data['compare_at_decimal']);
        self::assertSame(['/products/red-mug'], $record->data['legacy_paths']);
        self::assertSame([['language_code' => 'uk-UA', 'keyword' => 'red-mug']], $record->data['seo_urls']);
    }

    public function testPromRowsKeepGroupAttributesAndStock(): void
    {
        $headers = ['Код_товара', 'Название_позиции', 'Описание', 'Цена', 'Наличие', 'Количество', 'Название_группы', 'Ссылка_изображения', 'Производитель', 'Название_Характеристики', 'Значение_Характеристики', 'Название_Характеристики', 'Значение_Характеристики'];
        $rows = [['A-1', 'Чайник', 'Опис', '1 250,00', '+', '', 'Кухня', 'https://prom.test/1.jpg, https://prom.test/2.jpg', 'Bosch', 'Колір', 'Білий', 'Об\'єм', '1.7 л']];
        $p = PlatformCatalogProfiles::normalize('prom', $headers, $rows)['products'][0];
        self::assertSame('A-1', $p['sku']);
        self::assertSame('1250.00', $p['price']);
        self::assertSame(99, $p['stock']);
        self::assertSame([['Кухня']], $p['categories']);
        self::assertCount(2, $p['images']);
        self::assertSame('Bosch', $p['brand']);
        self::assertSame(['Колір' => 'Білий', 'Об\'єм' => '1.7 л'], $p['attributes']);
    }

    public function testWooCommerceSalePriceBecomesTheCurrentPriceAndVariationsAreSkipped(): void
    {
        $headers = ['Type', 'SKU', 'Name', 'Regular price', 'Sale price', 'Categories', 'Images', 'In stock?', 'Stock', 'Attribute 1 name', 'Attribute 1 value(s)'];
        $rows = [
            ['simple', 'W-1', 'Lamp', '500', '400', 'Home > Light, Sale', 'https://w.test/l.jpg', '1', '', 'Color', 'Black | White'],
            ['variation', 'W-1-a', 'Lamp - black', '500', '', '', '', '1', '3', '', ''],
        ];
        $result = PlatformCatalogProfiles::normalize('woocommerce', $headers, $rows);
        self::assertCount(1, $result['products']);
        $p = $result['products'][0];
        self::assertSame('400', $p['price']);
        self::assertSame('500', $p['old_price']);
        self::assertSame([['Home', 'Light'], ['Sale']], $p['categories']);
        self::assertSame(100, $p['stock']);
        self::assertSame(['Color' => 'Black, White'], $p['attributes']);
        self::assertSame(['variation_skipped:W-1-a'], $result['warnings']);
    }

    public function testTheSourceBuildsAUniqueCategoryTreeBrandsAndProductRecords(): void
    {
        $products = PlatformCatalogProfiles::normalize('generic', ['Артикул', 'Назва', 'Ціна', 'Категорія', 'Виробник', 'Фото'], [
            ['S-1', 'Перший', '100', 'Дім > Кухня', 'Acme', 'https://x.test/1.jpg'],
            ['S-2', 'Другий', '200', 'Дім > Кухня; Дім', 'acme', ''],
        ])['products'];
        $source = new FileCatalogSource('generic', $products, 'uk-UA', 'abc');
        $categories = $source->read(MigrationEntityType::Category, null, 100)->records;
        self::assertCount(2, $categories, 'Дім and Дім/Кухня exist once each');
        self::assertNull($categories[0]->data['parent_source_key']);
        self::assertSame($categories[0]->sourceKey, $categories[1]->data['parent_source_key']);
        self::assertCount(1, $source->read(MigrationEntityType::Brand, null, 100)->records, 'brand names are matched without case');
        $items = $source->read(MigrationEntityType::Product, null, 100)->records;
        self::assertCount(2, $items);
        self::assertSame('100', $items[0]->data['legacy_price_decimal']);
        self::assertSame(['https://x.test/1.jpg'], $items[0]->data['image_urls']);
        self::assertSame('Перший', $items[0]->data['translations']['uk-UA']['name']);
        $page = $source->read(MigrationEntityType::Product, null, 1);
        self::assertFalse($page->complete);
        self::assertTrue($source->read(MigrationEntityType::Product, $page->nextCursor, 1)->complete);
    }
}
