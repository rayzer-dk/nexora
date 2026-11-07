<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Analytics\Infrastructure\TrackingSettings;
use Commerce\Modules\Feeds\Application\CanonicalProductExportService;
use Commerce\Modules\Feeds\Application\ProductFeedGenerator;
use Commerce\Modules\Review\Application\GoogleProductReviewsFeed;
use Commerce\Modules\Rewards\Application\MerchantLoyaltyProgram;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GoogleCustomerFeaturesTest extends TestCase
{
    private function db(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    /** @return iterable<string,array{0:array<string,string>}> */
    public static function badTrackingInput(): iterable
    {
        yield 'ads id' => [['google_ads_id' => 'AW-12']];
        yield 'label without an ads id' => [['google_ads_purchase_label' => 'AbCdEf123']];
        yield 'label with a quote' => [['google_ads_id' => 'AW-123456789', 'google_ads_purchase_label' => "x'};alert(1)//"]];
        yield 'merchant id' => [['gcr_merchant_id' => '12a45']];
        yield 'delivery days' => [['gcr_delivery_days' => '61']];
    }

    /** @param array<string,string> $input */
    #[DataProvider('badTrackingInput')]
    public function testOnlyWellFormedGoogleIdsAreStored(array $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TrackingSettings($this->db()))->save(1, $input);
    }

    public function testStoredGoogleTagsReachThePageAndItsCsp(): void
    {
        $db = $this->db();
        $db->executeStatement('CREATE TABLE mc_tracking_settings (store_id INTEGER PRIMARY KEY, ga4_id TEXT, gtm_id TEXT, meta_pixel_id TEXT, google_ads_id TEXT, google_ads_purchase_label TEXT, gcr_merchant_id TEXT, gcr_delivery_days INTEGER, loyalty_program_label TEXT, updated_at TEXT)');
        $settings = new TrackingSettings($db);
        self::assertNull($settings->storefront(1));

        $db->executeStatement("INSERT INTO mc_tracking_settings VALUES (1,'','','','AW-123456789','AbC-d_eF123','987654321',5,'','2026-10-01')");
        $tags = $settings->storefront(1);

        self::assertSame(['AW-123456789', 'AbC-d_eF123', '987654321', 5], [$tags['ads'], $tags['ads_label'], $tags['gcr'], $tags['gcr_days']]);
        self::assertContains('https://www.googleadservices.com', $tags['csp']['script']);
        self::assertContains('https://apis.google.com', $tags['csp']['script']);
        self::assertSame('', $tags['ga4']);
    }

    public function testPointsFollowTheRuleOfTheOrderEarning(): void
    {
        self::assertSame(250, MerchantLoyaltyProgram::points(25000, 1, null));
        self::assertSame(500, MerchantLoyaltyProgram::points(25000, 2, null));
        self::assertSame(125, MerchantLoyaltyProgram::points(25099, 1, 50), 'whole major units, then the product percent');
        self::assertSame(0, MerchantLoyaltyProgram::points(25000, 1, 0));
        self::assertSame(0, MerchantLoyaltyProgram::points(-5, 3, null));
    }

    public function testTheGoogleProductFeedCarriesTheLoyaltyProgramOnlyWhenThereArePoints(): void
    {
        $db = $this->db();
        $generator = new ProductFeedGenerator(new CanonicalProductExportService($db, 'https://x.test'), $db, 'https://x.test');
        $base = ['item_group_id' => 'A', 'title' => 'T', 'description' => 'D', 'link' => 'https://x.test/a', 'image_link' => 'https://x.test/i.jpg', 'additional_image_link' => [], 'availability' => 'in_stock',
            'regular_price' => '100.00', 'sale_price' => null, 'currency' => 'UAH', 'brand' => 'B', 'gtin' => '4006381333931', 'mpn' => '', 'google_product_category' => '', 'product_type' => ''];
        $method = new \ReflectionMethod($generator, 'googleXml');
        $xml = (string) $method->invoke($generator, [
            ['id' => 'with'] + $base + ['loyalty' => ['label' => 'club', 'points' => 100]],
            ['id' => 'none'] + $base + ['loyalty' => null],
            ['id' => 'zero'] + $base + ['loyalty' => ['label' => 'club', 'points' => 0]],
        ])['content'];

        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $x = new \DOMXPath($doc);
        $x->registerNamespace('g', 'http://base.google.com/ns/1.0');
        self::assertSame(1, $x->query('//g:loyalty_program')->length);
        self::assertSame('club', $x->evaluate('string(//item[g:id="with"]/g:loyalty_program/g:program_label)'));
        self::assertSame('100', $x->evaluate('string(//item[g:id="with"]/g:loyalty_program/g:loyalty_points)'));
    }

    public function testTheReviewsFeedListsOnlyPublishedReviewsOfLinkedProducts(): void
    {
        $db = $this->db();
        foreach ([
            'CREATE TABLE mc_product (id INTEGER PRIMARY KEY, public_id BLOB, status TEXT, manufacturer_part_number TEXT, brand_id INTEGER)',
            'CREATE TABLE mc_brand (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE mc_store_product (product_id INTEGER, store_id INTEGER, status TEXT)',
            'CREATE TABLE mc_product_variant (id INTEGER PRIMARY KEY, product_id INTEGER, sort_order INTEGER, sku TEXT, gtin TEXT, mpn TEXT)',
            'CREATE TABLE mc_product_extra (product_id INTEGER, reviews_off INTEGER)',
            'CREATE TABLE mc_seo_route (store_id INTEGER, locale TEXT, entity_type TEXT, entity_public_id BLOB, path TEXT, indexable INTEGER)',
            'CREATE TABLE mc_product_review (id INTEGER PRIMARY KEY, public_id BLOB, store_id INTEGER, product_id INTEGER, locale TEXT, author_name TEXT, rating INTEGER, title TEXT, body TEXT, verified_purchase INTEGER, status TEXT, created_at TEXT, published_at TEXT)',
        ] as $ddl) {
            $db->executeStatement($ddl);
        }
        $db->executeStatement("INSERT INTO mc_brand VALUES (1,'Acme')");
        foreach ([[1, 'published', 0], [2, 'published', 1], [3, 'draft', 0]] as [$id, $status, $off]) {
            $pid = str_repeat(chr($id), 16);
            $db->executeStatement('INSERT INTO mc_product VALUES (?,?,?,?,1)', [$id, $pid, $status, 'MPN' . $id]);
            $db->executeStatement("INSERT INTO mc_store_product VALUES (?,1,'active')", [$id]);
            $db->executeStatement('INSERT INTO mc_product_variant VALUES (?,?,0,?,?,NULL)', [$id, $id, 'SKU' . $id, $id === 1 ? '4006381333931' : '']);
            $db->executeStatement('INSERT INTO mc_product_extra VALUES (?,?)', [$id, $off]);
            $db->executeStatement("INSERT INTO mc_seo_route VALUES (1,'uk-UA','product',?,?,1)", [$pid, '/p' . $id]);
        }
        $insert = static fn (int $id, int $product, string $status, string $body) => $db->executeStatement(
            "INSERT INTO mc_product_review VALUES (?,?,1,?,'uk-UA','Ірина',5,'Супер',?,1,?,'2026-10-01 10:00:00.000000',NULL)",
            [$id, str_repeat(chr(100 + $id), 16), $product, $body, $status],
        );
        $insert(1, 1, 'published', 'Дуже задоволена <b>якістю</b> & швидкістю');
        $insert(2, 1, 'pending', 'ще не опубліковано');
        $insert(3, 2, 'published', 'відгуки для цього товару вимкнені');
        $insert(4, 3, 'published', 'товар не опублікований');

        $xml = (new GoogleProductReviewsFeed($db, 'https://x.test'))->render(1, 'Nexora');

        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml), 'well-formed XML');
        $x = new \DOMXPath($doc);
        self::assertSame('2.3', $x->evaluate('string(/feed/version)'));
        self::assertSame('Nexora', $x->evaluate('string(/feed/publisher/name)'));
        self::assertSame(1, $x->query('//review')->length);
        self::assertSame('Дуже задоволена <b>якістю</b> & швидкістю', $x->evaluate('string(//review/content)'));
        self::assertSame('2026-10-01T10:00:00Z', $x->evaluate('string(//review/review_timestamp)'));
        self::assertSame('https://x.test/p1#reviews', $x->evaluate('string(//review/review_url)'));
        self::assertSame('singleton', $x->evaluate('string(//review/review_url/@type)'));
        self::assertSame('https://x.test/p1', $x->evaluate('string(//review/products/product/product_url)'));
        self::assertSame('4006381333931|MPN1|SKU1|Acme', implode('|', [
            $x->evaluate('string(//product_ids/gtins/gtin)'), $x->evaluate('string(//product_ids/mpns/mpn)'),
            $x->evaluate('string(//product_ids/skus/sku)'), $x->evaluate('string(//product_ids/brands/brand)'),
        ]));
        self::assertSame('post_fulfillment', $x->evaluate('string(//review/collection_method)'));
    }
}
