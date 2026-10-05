<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit\Supplier;

use Commerce\Modules\Supplier\Application\SupplierFeedParser;
use PHPUnit\Framework\TestCase;

final class SupplierFeedParserTest extends TestCase
{
    private function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'feed');
        file_put_contents($path, $content);

        return $path;
    }

    public function testYmlOffersWithStockAndAvailability(): void
    {
        $path = $this->file('<?xml version="1.0"?><yml_catalog><shop><offers>
            <offer id="1" available="true"><vendorCode>A-1</vendorCode><name>Drill</name><price>1 234,50</price><stock_quantity>7</stock_quantity><barcode>4001</barcode></offer>
            <offer id="2" available="false"><name>Saw</name><price>99.9</price></offer>
            <offer id="3"><vendorCode>C-3</vendorCode></offer>
        </offers></shop></yml_catalog>');
        $rows = iterator_to_array((new SupplierFeedParser())->parse($path, 'yml'), false);
        self::assertCount(2, $rows);
        self::assertSame(['sku' => 'A-1', 'name' => 'Drill', 'price' => 1234.5, 'stock' => 7.0, 'available' => true, 'gtin' => '4001'], $rows[0]);
        self::assertSame('2', $rows[1]['sku']);
        self::assertNull($rows[1]['stock']);
        self::assertFalse($rows[1]['available']);
    }

    public function testGenericXmlUsesTheMappedTags(): void
    {
        $path = $this->file('<root><product><code>X1</code><title>Hammer</title><cost>15</cost><qty>3</qty></product></root>');
        $rows = iterator_to_array((new SupplierFeedParser())->parse($path, 'xml', ['item' => 'product', 'sku' => 'code', 'name' => 'title', 'price' => 'cost', 'stock' => 'qty']), false);
        self::assertSame('X1', $rows[0]['sku']);
        self::assertSame(15.0, $rows[0]['price']);
        self::assertSame(3.0, $rows[0]['stock']);
    }

    public function testCsvDetectsTheDelimiterAndUkrainianHeaders(): void
    {
        $path = $this->file("\xEF\xBB\xBFАртикул;Назва;Ціна;Залишок\nK-1;Key;12,5;\u{0454}\nK-2;Lock;100;4\n;Broken;1;1\n");
        $rows = iterator_to_array((new SupplierFeedParser())->parse($path, 'csv'), false);
        self::assertCount(2, $rows);
        self::assertSame(12.5, $rows[0]['price']);
        self::assertTrue($rows[0]['available']);
        self::assertSame(4.0, $rows[1]['stock']);
    }

    public function testCsvWithoutRequiredColumnsIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        iterator_to_array((new SupplierFeedParser())->parse($this->file("a,b\n1,2\n"), 'csv'), false);
    }
}
