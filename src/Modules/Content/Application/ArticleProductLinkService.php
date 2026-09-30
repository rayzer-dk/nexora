<?php

declare(strict_types=1);

namespace Commerce\Modules\Content\Application;

use Doctrine\DBAL\Connection;

/** Two-way links between blog articles and products (edited as a SKU list on the article). */
final readonly class ArticleProductLinkService
{
    public const MAX_LINKS = 12;

    public function __construct(private Connection $db)
    {
    }

    /** @return list<string> SKUs in input order, unique, capped */
    public static function parseSkus(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/u', trim($raw)) ?: [];
        $skus = [];
        foreach ($parts as $part) {
            $part = mb_substr(trim($part), 0, 64);
            if ($part !== '' && !in_array($part, $skus, true)) {
                $skus[] = $part;
            }
            if (count($skus) >= self::MAX_LINKS) {
                break;
            }
        }

        return $skus;
    }

    /** Replaces the links of an article. Unknown SKUs and products outside the store are ignored. */
    public function replace(Connection $db, int $storeId, int $contentId, string $rawSkus): void
    {
        $db->delete('mc_blog_article_product', ['content_id' => $contentId]);
        $position = 0;
        foreach (self::parseSkus($rawSkus) as $sku) {
            $productId = $db->fetchOne(
                'SELECT v.product_id FROM mc_product_variant v JOIN mc_store_product sp ON sp.product_id=v.product_id AND sp.store_id=? WHERE v.sku=? ORDER BY v.sort_order LIMIT 1',
                [$storeId, $sku],
            );
            if ($productId === false) {
                continue;
            }
            $db->executeStatement('INSERT IGNORE INTO mc_blog_article_product (content_id,product_id,position) VALUES (?,?,?)', [$contentId, (int) $productId, $position++]);
        }
    }

    public function skusFor(int $contentId): string
    {
        return implode(', ', array_map('strval', $this->db->fetchFirstColumn(
            'SELECT (SELECT v.sku FROM mc_product_variant v WHERE v.product_id=ap.product_id ORDER BY v.sort_order LIMIT 1) FROM mc_blog_article_product ap WHERE ap.content_id=? ORDER BY ap.position',
            [$contentId],
        )));
    }

    /** @return list<array{name:string,path:string}> */
    public function productsForArticle(int $storeId, string $locale, int $contentId, int $limit = 8): array
    {
        return array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'path' => '/' . ltrim((string) $r['path'], '/')], $this->db->fetchAllAssociative(
            "SELECT pt.name,sr.path FROM mc_blog_article_product ap
             JOIN mc_product p ON p.id=ap.product_id AND p.status='published'
             JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? AND sp.status='active'
             JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=? AND pt.locale=?
             JOIN mc_seo_route sr ON sr.store_id=? AND sr.locale=? AND sr.entity_type='product' AND sr.entity_public_id=p.public_id
             WHERE ap.content_id=? ORDER BY ap.position LIMIT " . max(1, min(self::MAX_LINKS, $limit)),
            [$storeId, $storeId, $locale, $storeId, $locale, $contentId],
        ));
    }

    /** @return list<array{title:string,path:string}> */
    public function articlesForProduct(int $storeId, string $locale, int $productId, int $limit = 4): array
    {
        return array_map(static fn (array $r): array => ['title' => (string) $r['title'], 'path' => '/' . ltrim((string) $r['path'], '/')], $this->db->fetchAllAssociative(
            "SELECT ct.title,sr.path FROM mc_blog_article_product ap
             JOIN mc_content_entry ce ON ce.id=ap.content_id AND ce.store_id=? AND ce.content_type='article' AND ce.status='published' AND (ce.published_at IS NULL OR ce.published_at<=UTC_TIMESTAMP(6))
             JOIN mc_content_translation ct ON ct.content_id=ce.id AND ct.locale=?
             JOIN mc_seo_route sr ON sr.store_id=ce.store_id AND sr.locale=? AND sr.entity_type='blog_article' AND sr.entity_public_id=ce.public_id
             WHERE ap.product_id=? ORDER BY ce.published_at DESC LIMIT " . max(1, min(12, $limit)),
            [$storeId, $locale, $locale, $productId],
        ));
    }
}
