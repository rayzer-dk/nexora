<?php

declare(strict_types=1);

namespace Commerce\Modules\Content\Infrastructure;

use Commerce\Modules\Content\Application\BlogContentProcessor;
use Doctrine\DBAL\Connection;
use IntlDateFormatter;
use Symfony\Component\Uid\Uuid;

/** Read side of the blog for the storefront, feed and sitemap. Only published, non-future articles are visible. */
final readonly class DbalBlogQuery
{
    private const PUBLISHED = "ce.content_type='article' AND ce.status='published' AND (ce.published_at IS NULL OR ce.published_at<=UTC_TIMESTAMP(6))";
    private const SELECT = "ce.id,ce.public_id,ce.published_at,ce.updated_at,ct.title,ct.excerpt,ct.body_html,ct.meta_title,ct.meta_description,sr.path,
                    em.value_json AS image_meta,bm.cover_url,bm.cover_alt,bm.image_size,bm.image_align,bm.author_name,bm.featured,bm.noindex,bm.canonical_url,bm.reading_minutes,bm.category_id,
                    bc.slug AS category_slug,COALESCE(bct.name,bc.slug) AS category_name";
    private const JOINS = "FROM mc_content_entry ce
             JOIN mc_content_translation ct ON ct.content_id=ce.id AND ct.locale=:locale
             JOIN mc_seo_route sr ON sr.store_id=ce.store_id AND sr.locale=:locale AND sr.entity_type='blog_article' AND sr.entity_public_id=ce.public_id
             LEFT JOIN mc_blog_article_meta bm ON bm.content_id=ce.id
             LEFT JOIN mc_blog_category bc ON bc.id=bm.category_id AND bc.status='active'
             LEFT JOIN mc_blog_category_translation bct ON bct.category_id=bc.id AND bct.locale=:locale
             LEFT JOIN mc_entity_metadata em ON em.entity_type='article' AND em.entity_public_id=ce.public_id AND em.namespace=:ns AND em.meta_key='image'";

    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array<string,mixed>> */
    public function latest(int $storeId, string $locale, int $limit = 12): array
    {
        return $this->page($storeId, $locale, 1, max(1, min(50, $limit)))['items'];
    }

    /**
     * @return array{items:list<array<string,mixed>>,total:int,pages:int,page:int}
     */
    public function page(int $storeId, string $locale, int $page, int $perPage = 12, ?string $categorySlug = null, ?string $tag = null, ?string $search = null, bool $excludeFeatured = false): array
    {
        $perPage = max(1, min(50, $perPage));
        $where = 'ce.store_id=:store AND ' . self::PUBLISHED;
        $params = ['locale' => $locale, 'ns' => 'demo-' . $storeId, 'store' => $storeId];
        if ($categorySlug !== null && $categorySlug !== '') {
            // A category shows its own articles and those of every subcategory below it.
            $branch = $this->branchIds($storeId, $categorySlug);
            $where .= $branch === [] ? ' AND 1=0' : ' AND bm.category_id IN (' . implode(',', $branch) . ')';
        }
        if ($tag !== null && $tag !== '') {
            $where .= ' AND EXISTS (SELECT 1 FROM mc_blog_article_tag bt WHERE bt.content_id=ce.id AND bt.tag_slug=:tag)';
            $params['tag'] = $tag;
        }
        if ($search !== null && trim($search) !== '') {
            $where .= ' AND (ct.title LIKE :q OR ct.excerpt LIKE :q)';
            $params['q'] = '%' . addcslashes(trim($search), '%_\\') . '%';
        }
        if ($excludeFeatured) {
            $where .= ' AND COALESCE(bm.featured,0)=0';
        }
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) ' . self::JOINS . ' WHERE ' . $where, $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, $page);
        $rows = $page > $pages ? [] : $this->connection->fetchAllAssociative(
            'SELECT ' . self::SELECT . ' ' . self::JOINS . ' WHERE ' . $where . ' ORDER BY ce.published_at DESC,ce.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params,
        );
        return ['items' => array_map(fn (array $r): array => $this->card($r, $locale), $rows), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /** @return array<string,mixed>|null the newest featured article, shown as the hero on the first blog page */
    public function featured(int $storeId, string $locale): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::SELECT . ' ' . self::JOINS . ' WHERE ce.store_id=:store AND ' . self::PUBLISHED . ' AND bm.featured=1 ORDER BY ce.published_at DESC,ce.id DESC LIMIT 1',
            ['locale' => $locale, 'ns' => 'demo-' . $storeId, 'store' => $storeId],
        );
        return is_array($row) ? $this->card($row, $locale) : null;
    }

    /**
     * Active categories as a tree (parents before their children). "articles" counts the category and everything below it.
     *
     * @return list<array{slug:string,name:string,description:string,articles:int,parent:?string,depth:int}>
     */
    public function categories(int $storeId, string $locale): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT c.id,c.parent_id,c.slug,COALESCE(t.name,c.slug) AS name,COALESCE(t.description,'') AS description,
                    (SELECT COUNT(*) FROM mc_blog_article_meta bm
                       JOIN mc_content_entry ce ON ce.id=bm.content_id
                       JOIN mc_content_translation ct ON ct.content_id=ce.id AND ct.locale=:locale
                       JOIN mc_seo_route sr ON sr.store_id=ce.store_id AND sr.locale=:locale AND sr.entity_type='blog_article' AND sr.entity_public_id=ce.public_id
                      WHERE bm.category_id=c.id AND " . self::PUBLISHED . ") AS own_articles
             FROM mc_blog_category c LEFT JOIN mc_blog_category_translation t ON t.category_id=c.id AND t.locale=:locale
             WHERE c.store_id=:store AND c.status='active' ORDER BY c.sort_order,name",
            ['locale' => $locale, 'store' => $storeId],
        );
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        // A subcategory whose parent is hidden is not reachable through the tree: it is shown as a top-level one.
        $children = [];
        foreach ($byId as $id => $r) {
            $parent = $r['parent_id'] !== null && isset($byId[(int) $r['parent_id']]) ? (int) $r['parent_id'] : 0;
            $children[$parent][] = $id;
        }
        $total = function (int $id) use (&$total, $byId, $children): int {
            $sum = (int) $byId[$id]['own_articles'];
            foreach ($children[$id] ?? [] as $child) {
                $sum += $total($child);
            }

            return $sum;
        };
        $out = [];
        $walk = function (int $parentKey, int $depth) use (&$walk, &$out, $byId, $children, $total): void {
            foreach ($children[$parentKey] ?? [] as $id) {
                $row = $byId[$id];
                $articles = $total($id);
                if ($articles > 0 && $depth < 6) {
                    $parentRow = $row['parent_id'] !== null ? ($byId[(int) $row['parent_id']] ?? null) : null;
                    $out[] = ['slug' => (string) $row['slug'], 'name' => (string) $row['name'], 'description' => (string) $row['description'], 'articles' => $articles, 'parent' => $parentRow !== null ? (string) $parentRow['slug'] : null, 'depth' => $depth];
                    $walk($id, $depth + 1);
                }
            }
        };
        $walk(0, 0);

        return $out;
    }

    /** @return array{slug:string,name:string,description:string,meta_title:string,meta_description:string,parent:string}|null */
    public function category(int $storeId, string $locale, string $slug): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT c.slug,COALESCE(t.name,c.slug) AS name,COALESCE(t.description,'') AS description,COALESCE(t.meta_title,'') AS meta_title,COALESCE(t.meta_description,'') AS meta_description,
                    COALESCE((SELECT p.slug FROM mc_blog_category p WHERE p.id=c.parent_id AND p.status='active'),'') AS parent
             FROM mc_blog_category c LEFT JOIN mc_blog_category_translation t ON t.category_id=c.id AND t.locale=?
             WHERE c.store_id=? AND c.slug=? AND c.status='active' LIMIT 1",
            [$locale, $storeId, $slug],
        );
        return is_array($row) ? array_map('strval', $row) : null;
    }

    /** @return list<array{slug:string,name:string}> the category and its parents, from the top level down (for breadcrumbs) */
    public function categoryTrail(int $storeId, string $locale, string $slug): array
    {
        $trail = [];
        for ($depth = 0; $slug !== '' && $depth < 8; ++$depth) {
            $row = $this->category($storeId, $locale, $slug);
            if ($row === null) {
                break;
            }
            array_unshift($trail, ['slug' => $row['slug'], 'name' => $row['name']]);
            $slug = $row['parent'];
        }

        return $trail;
    }

    /** @return list<int> the id of the category with this slug and of all its active subcategories */
    private function branchIds(int $storeId, string $slug): array
    {
        $all = [];
        foreach ($this->connection->fetchAllAssociative("SELECT id,parent_id,slug FROM mc_blog_category WHERE store_id=? AND status='active'", [$storeId]) as $r) {
            $all[(int) $r['id']] = ['parent' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null, 'slug' => (string) $r['slug']];
        }
        $root = null;
        foreach ($all as $id => $row) {
            if ($row['slug'] === $slug) {
                $root = $id;
                break;
            }
        }
        if ($root === null) {
            return [];
        }
        $ids = [$root];
        for ($round = 0; $round < 8; ++$round) {
            $added = false;
            foreach ($all as $id => $row) {
                if ($row['parent'] !== null && in_array($row['parent'], $ids, true) && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                    $added = true;
                }
            }
            if (!$added) {
                break;
            }
        }

        return $ids;
    }

    /** @return list<array{slug:string,name:string,articles:int}> */
    public function tags(int $storeId, string $locale, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->connection->fetchAllAssociative(
            "SELECT bt.tag_slug AS slug,MIN(bt.tag_name) AS name,COUNT(*) AS articles
             FROM mc_blog_article_tag bt
             JOIN mc_content_entry ce ON ce.id=bt.content_id
             JOIN mc_content_translation ct ON ct.content_id=ce.id AND ct.locale=:locale
             JOIN mc_seo_route sr ON sr.store_id=ce.store_id AND sr.locale=:locale AND sr.entity_type='blog_article' AND sr.entity_public_id=ce.public_id
             WHERE ce.store_id=:store AND " . self::PUBLISHED . " GROUP BY bt.tag_slug ORDER BY articles DESC,name LIMIT " . $limit,
            ['locale' => $locale, 'store' => $storeId],
        );
        return array_map(static fn (array $r): array => ['slug' => (string) $r['slug'], 'name' => (string) $r['name'], 'articles' => (int) $r['articles']], $rows);
    }

    /** @return string|null tag display name when at least one published article carries the tag */
    public function tagName(int $storeId, string $locale, string $slug): ?string
    {
        foreach ($this->tags($storeId, $locale, 100) as $tag) {
            if ($tag['slug'] === $slug) {
                return $tag['name'];
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public function byPublicId(int $storeId, string $locale, string $publicId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::SELECT . ' ' . self::JOINS . ' WHERE ce.store_id=:store AND ' . self::PUBLISHED . ' AND ce.public_id=:pid LIMIT 1',
            ['locale' => $locale, 'ns' => 'demo-' . $storeId, 'store' => $storeId, 'pid' => Uuid::fromString($publicId)->toBinary()],
        );
        if (!is_array($row)) {
            return null;
        }
        $card = $this->card($row, $locale);
        $processed = BlogContentProcessor::withAnchors((string) ($row['body_html'] ?? ''));
        $tags = $this->connection->fetchAllAssociative('SELECT tag_slug AS slug,tag_name AS name FROM mc_blog_article_tag WHERE content_id=? ORDER BY tag_name', [(int) $row['id']]);
        return $card + [
            'id' => (int) $row['id'],
            'public_id' => $publicId,
            'body_html' => $processed['html'],
            'toc' => count($processed['toc']) >= 3 ? $processed['toc'] : [],
            'meta_title' => (string) ($row['meta_title'] ?: $row['title']),
            'meta_description' => (string) ($row['meta_description'] ?: $card['excerpt']),
            'published_at' => (string) $row['published_at'],
            'updated_at' => (string) $row['updated_at'],
            'published_iso' => $this->iso($row['published_at'] ?? $row['updated_at']),
            'updated_iso' => $this->iso($row['updated_at']),
            'canonical_url' => (string) ($row['canonical_url'] ?? ''),
            'noindex' => (int) ($row['noindex'] ?? 0) === 1,
            'category_id' => (int) ($row['category_id'] ?? 0),
            'tags' => array_map(static fn (array $t): array => ['slug' => (string) $t['slug'], 'name' => (string) $t['name']], $tags),
        ];
    }

    /**
     * Related articles: same category or shared tags first, then the newest others, so the article rail is never empty.
     *
     * @return list<array<string,mixed>>
     */
    public function related(int $storeId, string $locale, int $contentId, int $categoryId, int $limit = 3): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::SELECT . ',
                    (SELECT COUNT(*) FROM mc_blog_article_tag a JOIN mc_blog_article_tag b ON b.tag_slug=a.tag_slug AND b.content_id=:self WHERE a.content_id=ce.id) AS shared_tags
             ' . self::JOINS . ' WHERE ce.store_id=:store AND ' . self::PUBLISHED . ' AND ce.id<>:self
             ORDER BY (bm.category_id=:cat) DESC,shared_tags DESC,ce.published_at DESC LIMIT ' . max(1, min(12, $limit)),
            ['locale' => $locale, 'ns' => 'demo-' . $storeId, 'store' => $storeId, 'self' => $contentId, 'cat' => $categoryId],
        );
        return array_map(fn (array $r): array => $this->card($r, $locale), $rows);
    }

    /**
     * Neighbouring articles by publication date.
     *
     * @return array{prev:?array<string,mixed>,next:?array<string,mixed>}
     */
    public function neighbors(int $storeId, string $locale, int $contentId, string $publishedAt): array
    {
        $base = ['locale' => $locale, 'ns' => 'demo-' . $storeId, 'store' => $storeId, 'at' => $publishedAt, 'self' => $contentId];
        $prev = $this->connection->fetchAssociative('SELECT ' . self::SELECT . ' ' . self::JOINS . ' WHERE ce.store_id=:store AND ' . self::PUBLISHED . ' AND (ce.published_at<:at OR (ce.published_at=:at AND ce.id<:self)) ORDER BY ce.published_at DESC,ce.id DESC LIMIT 1', $base);
        $next = $this->connection->fetchAssociative('SELECT ' . self::SELECT . ' ' . self::JOINS . ' WHERE ce.store_id=:store AND ' . self::PUBLISHED . ' AND (ce.published_at>:at OR (ce.published_at=:at AND ce.id>:self)) ORDER BY ce.published_at ASC,ce.id ASC LIMIT 1', $base);
        return ['prev' => is_array($prev) ? $this->card($prev, $locale) : null, 'next' => is_array($next) ? $this->card($next, $locale) : null];
    }

    /** @return array<string,string> locale => path of every published translation (for hreflang) */
    public function alternates(int $storeId, string $publicId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT sr.locale,sr.path FROM mc_seo_route sr
             JOIN mc_content_entry ce ON ce.public_id=sr.entity_public_id AND ce.store_id=sr.store_id
             JOIN mc_content_translation ct ON ct.content_id=ce.id AND ct.locale=sr.locale
             JOIN mc_store_locale sl ON sl.store_id=sr.store_id AND sl.locale_code=sr.locale AND sl.enabled=1
             WHERE sr.store_id=? AND sr.entity_type='blog_article' AND sr.entity_public_id=? AND sr.indexable=1 AND " . self::PUBLISHED,
            [$storeId, Uuid::fromString($publicId)->toBinary()],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['locale']] = '/' . ltrim((string) $r['path'], '/');
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function feed(int $storeId, string $locale, int $limit = 30): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::SELECT . ' ' . self::JOINS . ' WHERE ce.store_id=:store AND ' . self::PUBLISHED . ' AND COALESCE(bm.noindex,0)=0 ORDER BY ce.published_at DESC,ce.id DESC LIMIT ' . max(1, min(100, $limit)),
            ['locale' => $locale, 'ns' => 'demo-' . $storeId, 'store' => $storeId],
        );
        return array_map(fn (array $r): array => $this->card($r, $locale) + ['body_html' => (string) ($r['body_html'] ?? '')], $rows);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function card(array $r, string $locale): array
    {
        $meta = is_string($r['image_meta'] ?? null) ? json_decode($r['image_meta'], true) : null;
        $image = (string) ($r['cover_url'] ?? '');
        if ($image === '' && is_array($meta) && isset($meta['path'])) {
            $image = '/media/' . ltrim((string) $meta['path'], '/');
        }
        $excerpt = trim((string) ($r['excerpt'] ?? ''));
        if ($excerpt === '') {
            $excerpt = BlogContentProcessor::excerpt((string) ($r['body_html'] ?? ''), 160);
        }
        $published = $r['published_at'] ?? null;
        return [
            'public_id' => Uuid::fromBinary((string) $r['public_id'])->toRfc4122(),
            'title' => (string) $r['title'],
            'excerpt' => $excerpt,
            'url' => '/' . ltrim((string) $r['path'], '/'),
            'date' => $published ? $this->formatDate((string) $published, $locale) : '',
            'iso' => $published ? $this->iso($published) : '',
            'image' => $image,
            'image_size' => in_array((string) ($r['image_size'] ?? ''), ['s', 'm', 'l'], true) ? (string) $r['image_size'] : 'm',
            'image_align' => in_array((string) ($r['image_align'] ?? ''), ['none', 'left', 'right', 'hide'], true) ? (string) $r['image_align'] : 'none',
            'image_alt' => (string) (($r['cover_alt'] ?? '') !== '' ? $r['cover_alt'] : $r['title']),
            'author' => (string) ($r['author_name'] ?? ''),
            'featured' => (int) ($r['featured'] ?? 0) === 1,
            'reading_minutes' => max(1, (int) ($r['reading_minutes'] ?? 1)),
            'category' => ($r['category_slug'] ?? null) !== null ? ['slug' => (string) $r['category_slug'], 'name' => (string) $r['category_name']] : null,
        ];
    }

    private function formatDate(string $utc, string $locale): string
    {
        $time = strtotime($utc . ' UTC');
        if ($time === false) {
            return '';
        }
        if (class_exists(IntlDateFormatter::class)) {
            $formatter = new IntlDateFormatter(str_replace('-', '_', $locale), IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC');
            $text = $formatter->format($time);
            if (is_string($text) && $text !== '') {
                return $text;
            }
        }
        return gmdate('d.m.Y', $time);
    }

    private function iso(mixed $utc): string
    {
        $time = strtotime((string) $utc . ' UTC');
        return $time === false ? '' : gmdate('c', $time);
    }
}
