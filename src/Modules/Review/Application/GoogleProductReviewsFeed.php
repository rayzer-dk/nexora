<?php

declare(strict_types=1);

namespace Commerce\Modules\Review\Application;

use Doctrine\DBAL\Connection;

/**
 * Published product reviews in the Google "product reviews" XML feed (schema 2.3), read by Merchant Center on a schedule.
 * A review is listed with the identifiers of its product; one that has no product page link is left out, since Google rejects it.
 */
final readonly class GoogleProductReviewsFeed
{
    private const LIMIT = 50000;

    public function __construct(private Connection $db, private string $publicBaseUrl)
    {
    }

    public function render(int $storeId, string $publisher): string
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT r.public_id,r.author_name,r.rating,r.title,r.body,r.verified_purchase,COALESCE(r.published_at,r.created_at) reviewed_at,
                    v.sku,v.gtin,COALESCE(v.mpn,p.manufacturer_part_number) mpn,COALESCE(b.name,'') brand,sr.path
             FROM mc_product_review r
             JOIN mc_product p ON p.id=r.product_id AND p.status='published'
             JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=r.store_id AND sp.status='active'
             JOIN mc_product_variant v ON v.product_id=p.id AND v.sort_order=0
             JOIN mc_seo_route sr ON sr.store_id=r.store_id AND sr.locale=r.locale AND sr.entity_type='product' AND sr.entity_public_id=p.public_id AND sr.indexable=1
             LEFT JOIN mc_product_extra pe ON pe.product_id=p.id
             LEFT JOIN mc_brand b ON b.id=p.brand_id
             WHERE r.store_id=? AND r.status='published' AND COALESCE(pe.reviews_off,0)=0
             ORDER BY r.id DESC LIMIT " . self::LIMIT,
            [$storeId],
        );
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $feed = $xml->createElement('feed');
        $feed->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $feed->setAttribute('xsi:noNamespaceSchemaLocation', 'http://www.google.com/shopping/reviews/schema/product/2.3/product_reviews.xsd');
        $xml->appendChild($feed);
        $this->text($xml, $feed, 'version', '2.3');
        $this->text($xml, $this->child($xml, $feed, 'publisher'), 'name', $publisher);
        $reviews = $this->child($xml, $feed, 'reviews');
        foreach ($rows as $row) {
            $link = rtrim($this->publicBaseUrl, '/') . '/' . ltrim((string) $row['path'], '/');
            $review = $this->child($xml, $reviews, 'review');
            $this->text($xml, $review, 'review_id', bin2hex((string) $row['public_id']));
            $this->text($xml, $this->child($xml, $review, 'reviewer'), 'name', (string) $row['author_name']);
            $this->text($xml, $review, 'review_timestamp', (new \DateTimeImmutable((string) $row['reviewed_at'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'));
            if (trim((string) $row['title']) !== '') {
                $this->text($xml, $review, 'title', (string) $row['title']);
            }
            $this->text($xml, $review, 'content', (string) $row['body']);
            $url = $this->text($xml, $review, 'review_url', $link . '#reviews');
            $url->setAttribute('type', 'singleton');
            $overall = $this->text($xml, $this->child($xml, $review, 'ratings'), 'overall', (string) (int) $row['rating']);
            $overall->setAttribute('min', '1');
            $overall->setAttribute('max', '5');
            $ids = $this->child($xml, $this->child($xml, $this->child($xml, $review, 'products'), 'product'), 'product_ids');
            foreach ([['gtins', 'gtin', 'gtin'], ['mpns', 'mpn', 'mpn'], ['skus', 'sku', 'sku'], ['brands', 'brand', 'brand']] as [$group, $tag, $field]) {
                $value = trim((string) $row[$field]);
                if ($value !== '') {
                    $this->text($xml, $this->child($xml, $ids, $group), $tag, $value);
                }
            }
            $this->text($xml, $ids->parentNode, 'product_url', $link);
            if ((int) $row['verified_purchase'] === 1) {
                $this->text($xml, $review, 'collection_method', 'post_fulfillment');
            }
        }

        return (string) $xml->saveXML();
    }

    private function child(\DOMDocument $xml, \DOMNode $parent, string $name): \DOMElement
    {
        $node = $xml->createElement($name);
        $parent->appendChild($node);

        return $node;
    }

    private function text(\DOMDocument $xml, \DOMNode $parent, string $name, string $value): \DOMElement
    {
        $node = $xml->createElement($name);
        $node->appendChild($xml->createTextNode($value));
        $parent->appendChild($node);

        return $node;
    }
}
