<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Feeds\Application\CanonicalProductExportService;
use Commerce\Modules\Feeds\Application\ProductFeedGenerator;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class GoogleFeedXmlTest extends TestCase
{
    /** @return array<string,mixed> */
    private function product(string $id, string $group, string $gtin = '', string $mpn = ''): array
    {
        return [
            'id' => $id, 'item_group_id' => $group, 'title' => 'T ' . $id, 'description' => 'D', 'link' => 'https://x.test/' . $id, 'image_link' => 'https://x.test/i.jpg',
            'additional_image_link' => [], 'availability' => 'in_stock', 'regular_price' => '100.00', 'sale_price' => null, 'currency' => 'UAH',
            'brand' => 'Brand', 'gtin' => $gtin, 'mpn' => $mpn, 'google_product_category' => '', 'product_type' => '',
        ];
    }

    public function testIdentifiersAndVariantGroupsFollowTheGoogleRules(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $generator = new ProductFeedGenerator(new CanonicalProductExportService($db, 'https://x.test'), $db, 'https://x.test');
        $method = new \ReflectionMethod($generator, 'googleXml');
        $xml = (string) $method->invoke($generator, [
            $this->product('a1', 'A', '4006381333931'),
            $this->product('a2', 'A', '', 'MPN-2'),
            $this->product('b1', 'B'),
        ])['content'];

        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $x = new \DOMXPath($doc);
        $x->registerNamespace('g', 'http://base.google.com/ns/1.0');
        $items = $x->query('//item');
        self::assertSame(3, $items->length);
        // variants of one product share a group id; a product with a single item has none
        self::assertSame(2, $x->query('//g:item_group_id')->length);
        self::assertSame(0, $x->query('//item[g:id="b1"]/g:item_group_id')->length);
        // an item with neither a GTIN nor an MPN says so (otherwise Merchant Center asks for an identifier)
        self::assertSame(['b1'], array_map(static fn (\DOMNode $n): string => (string) $x->evaluate('string(../g:id)', $n), iterator_to_array($x->query('//g:identifier_exists'))));
        self::assertSame('no', $x->evaluate('string(//item[g:id="b1"]/g:identifier_exists)'));
        self::assertSame('100.00 UAH', $x->evaluate('string(//item[g:id="a1"]/g:price)'));
    }
}
