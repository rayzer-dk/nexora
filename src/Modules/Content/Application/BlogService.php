<?php

declare(strict_types=1);

namespace Commerce\Modules\Content\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Seo\Application\SeoUrlManager;
use Commerce\Modules\Seo\Application\SlugGenerator;
use Commerce\Modules\Seo\Contract\SeoUrlRepositoryInterface;
use Commerce\Modules\Seo\Domain\SeoEntityType;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\Uid\Uuid;

/** Write side of the blog: articles (per locale), categories and tags. */
final class BlogService
{
    /** Levels below the top one (top level = 0). */
    private const MAX_CATEGORY_DEPTH = 3;

    public const STATUSES = ['draft', 'published'];
    private const MAX_TAGS = 10;

    public function __construct(
        private readonly Connection $db,
        private readonly PublicIdFactory $publicIds,
        private readonly SeoUrlManager $seo,
        private readonly SeoUrlRepositoryInterface $routes,
        private readonly SlugGenerator $slugs,
        private readonly HtmlSanitizerInterface $richTextSanitizer,
        private readonly ArticleProductLinkService $links,
    ) {
    }

    /**
     * @param array{status?:string,q?:string,category?:int} $filters
     * @return array{items:list<array<string,mixed>>,total:int,pages:int,page:int}
     */
    public function list(int $storeId, string $locale, array $filters, int $page, int $perPage = 20): array
    {
        $where = ["ce.store_id = ?", "ce.content_type = 'article'"];
        $params = [$locale, $storeId];
        $states = array_values(array_intersect(array_map('strval', (array) ($filters['status'] ?? [])), ['draft', 'published', 'scheduled']));
        if ($states !== [] && count($states) < 3) {
            $parts = [];
            foreach ($states as $state) {
                $parts[] = match ($state) {
                    'draft' => "ce.status = 'draft'",
                    'published' => "(ce.status = 'published' AND (ce.published_at IS NULL OR ce.published_at <= UTC_TIMESTAMP(6)))",
                    default => "(ce.status = 'published' AND ce.published_at > UTC_TIMESTAMP(6))",
                };
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = 'ct.title LIKE ?';
            $params[] = '%' . addcslashes($q, '%_\\') . '%';
        }
        $categories = array_values(array_filter(array_map('intval', (array) ($filters['category'] ?? [])), static fn (int $id): bool => $id > 0));
        if ($categories !== []) {
            $where[] = 'bm.category_id IN (' . implode(',', array_fill(0, count($categories), '?')) . ')';
            array_push($params, ...$categories);
        }
        $from = "FROM mc_content_entry ce
                 LEFT JOIN mc_content_translation ct ON ct.content_id = ce.id AND ct.locale = ?
                 LEFT JOIN mc_blog_article_meta bm ON bm.content_id = ce.id
                 WHERE " . implode(' AND ', $where);
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) ' . $from, $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $rows = $this->db->fetchAllAssociative(
            "SELECT ce.id, ce.status, ce.published_at, ce.updated_at, ct.title, bm.category_id, bm.featured, bm.reading_minutes,
                    (SELECT ct2.title FROM mc_content_translation ct2 WHERE ct2.content_id = ce.id ORDER BY ct2.id LIMIT 1) AS any_title,
                    (SELECT GROUP_CONCAT(ct3.locale) FROM mc_content_translation ct3 WHERE ct3.content_id = ce.id) AS locales
             " . $from . ' ORDER BY COALESCE(ce.published_at, ce.updated_at) DESC, ce.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params,
        );
        $now = gmdate('Y-m-d H:i:s');
        foreach ($rows as &$row) {
            $row['has_locale'] = (string) ($row['title'] ?? '') !== '';
            $row['title'] = $row['has_locale'] ? (string) $row['title'] : (string) $row['any_title'];
            $row['state'] = (string) $row['status'] === 'draft'
                ? 'draft'
                : (($row['published_at'] ?? null) !== null && (string) $row['published_at'] > $now ? 'scheduled' : 'published');
        }
        unset($row);
        return ['items' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /** @return array<string,mixed>|null */
    public function find(int $storeId, int $id, string $locale): ?array
    {
        $entry = $this->db->fetchAssociative(
            "SELECT id, public_id, status, published_at, author_subject FROM mc_content_entry WHERE id = ? AND store_id = ? AND content_type = 'article'",
            [$id, $storeId],
        );
        if (!is_array($entry)) {
            return null;
        }
        $publicId = Uuid::fromBinary((string) $entry['public_id'])->toRfc4122();
        $tr = $this->db->fetchAssociative('SELECT title, excerpt, body_html, meta_title, meta_description FROM mc_content_translation WHERE content_id = ? AND locale = ?', [$id, $locale]);
        $meta = $this->db->fetchAssociative('SELECT category_id, cover_url, cover_alt, image_size, image_align, author_name, featured, noindex, canonical_url, reading_minutes FROM mc_blog_article_meta WHERE content_id = ?', [$id]);
        $route = $this->routes->findByEntity($storeId, $locale, SeoEntityType::BlogArticle, $publicId);
        $tags = $this->db->fetchFirstColumn('SELECT tag_name FROM mc_blog_article_tag WHERE content_id = ? ORDER BY tag_name', [$id]);
        return [
            'id' => $id,
            'public_id' => $publicId,
            'status' => (string) $entry['status'],
            'published_at' => $entry['published_at'] !== null ? substr((string) $entry['published_at'], 0, 16) : '',
            'has_translation' => is_array($tr),
            'title' => (string) ($tr['title'] ?? ''),
            'excerpt' => (string) ($tr['excerpt'] ?? ''),
            'body_html' => (string) ($tr['body_html'] ?? ''),
            'meta_title' => (string) ($tr['meta_title'] ?? ''),
            'meta_description' => (string) ($tr['meta_description'] ?? ''),
            'slug' => $route?->slug ?? '',
            'path' => $route !== null ? '/' . ltrim($route->path, '/') : '',
            'category_id' => (int) ($meta['category_id'] ?? 0),
            'cover_url' => (string) ($meta['cover_url'] ?? ''),
            'cover_alt' => (string) ($meta['cover_alt'] ?? ''),
            'image_size' => in_array((string) ($meta['image_size'] ?? ''), ['s', 'm', 'l'], true) ? (string) $meta['image_size'] : 'm',
            'image_align' => in_array((string) ($meta['image_align'] ?? ''), ['none', 'left', 'right', 'hide'], true) ? (string) $meta['image_align'] : 'none',
            'author_name' => (string) ($meta['author_name'] ?? ''),
            'featured' => (int) ($meta['featured'] ?? 0) === 1,
            'noindex' => (int) ($meta['noindex'] ?? 0) === 1,
            'canonical_url' => (string) ($meta['canonical_url'] ?? ''),
            'tags' => implode(', ', array_map('strval', $tags)),
            'product_skus' => $this->links->skusFor($id),
        ];
    }

    /**
     * Creates or updates the article in one locale.
     *
     * @param array<string,mixed> $in title, slug, excerpt, body_html, meta_title, meta_description, status, published_at,
     *        category_id, cover_url, cover_alt, author_name, featured, noindex, canonical_url, tags
     */
    public function save(int $storeId, string $locale, ?int $id, array $in, string $authorSubject): int
    {
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '' || mb_strlen($title, 'UTF-8') > 255) {
            throw new InvalidArgumentException(CanonicalUiText::get('admin.blog.error.title'));
        }
        $status = (string) ($in['status'] ?? 'draft');
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(CanonicalUiText::get('admin.blog.error.status'));
        }
        $rawBody = trim((string) ($in['body_html'] ?? ''));
        $body = $rawBody === '' ? '' : trim($this->richTextSanitizer->sanitize($rawBody));
        if ($status === 'published' && BlogContentProcessor::plainText($body) === '' && !str_contains($body, '<img')) {
            throw new InvalidArgumentException(CanonicalUiText::get('admin.blog.error.body'));
        }
        $canonical = trim((string) ($in['canonical_url'] ?? ''));
        if ($canonical !== '' && !preg_match('#^https?://[^\s]+$#i', $canonical)) {
            throw new InvalidArgumentException(CanonicalUiText::get('admin.blog.error.canonical'));
        }
        $cover = trim((string) ($in['cover_url'] ?? ''));
        if ($cover !== '' && !preg_match('#^(/|https://)[^\s"<>]+$#', $cover)) {
            throw new InvalidArgumentException(CanonicalUiText::get('admin.blog.error.cover'));
        }
        $categoryId = (int) ($in['category_id'] ?? 0);
        if ($categoryId > 0 && (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_blog_category WHERE id = ? AND store_id = ?', [$categoryId, $storeId]) !== 1) {
            $categoryId = 0;
        }
        $publishedAt = $this->parseDate((string) ($in['published_at'] ?? ''));
        $now = $this->now();
        if ($status === 'published' && $publishedAt === null) {
            $publishedAt = $now;
        }
        $excerpt = trim((string) ($in['excerpt'] ?? ''));
        $manualSlug = trim((string) ($in['slug'] ?? ''));

        $publicId = null;
        $entryId = $this->db->transactional(function (Connection $db) use ($storeId, $locale, $id, $title, $status, $body, $excerpt, $in, $canonical, $cover, $categoryId, $publishedAt, $now, $authorSubject, &$publicId): int {
            if ($id === null) {
                $uuid = $this->publicIds->generate();
                $publicId = $uuid->toRfc4122();
                $db->insert('mc_content_entry', [
                    'public_id' => $uuid->toBinary(), 'store_id' => $storeId, 'content_type' => 'article', 'system_key' => null,
                    'status' => $status, 'author_subject' => mb_substr($authorSubject, 0, 190, 'UTF-8'), 'published_at' => $publishedAt,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $entryId = (int) $db->lastInsertId();
            } else {
                $row = $db->fetchAssociative("SELECT public_id FROM mc_content_entry WHERE id = ? AND store_id = ? AND content_type = 'article' FOR UPDATE", [$id, $storeId]);
                if (!is_array($row)) {
                    throw new InvalidArgumentException(CanonicalUiText::get('admin.blog.error.not_found'));
                }
                $publicId = Uuid::fromBinary((string) $row['public_id'])->toRfc4122();
                $db->update('mc_content_entry', ['status' => $status, 'published_at' => $publishedAt, 'updated_at' => $now], ['id' => $id]);
                $entryId = $id;
            }
            $fields = [
                'title' => $title,
                'excerpt' => $excerpt === '' ? null : mb_substr($excerpt, 0, 1000, 'UTF-8'),
                'body_html' => $body === '' ? null : $body,
                'meta_title' => $this->nullable((string) ($in['meta_title'] ?? ''), 255),
                'meta_description' => $this->nullable((string) ($in['meta_description'] ?? ''), 500),
                'updated_at' => $now,
            ];
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_content_translation WHERE content_id = ? AND locale = ?', [$entryId, $locale]) === 1) {
                $db->update('mc_content_translation', $fields, ['content_id' => $entryId, 'locale' => $locale]);
            } else {
                $db->insert('mc_content_translation', $fields + ['content_id' => $entryId, 'locale' => $locale, 'created_at' => $now]);
            }

            $metaFields = [
                'category_id' => $categoryId > 0 ? $categoryId : null,
                'cover_url' => $cover === '' ? null : $cover,
                'cover_alt' => $this->nullable((string) ($in['cover_alt'] ?? ''), 255),
                'image_size' => in_array((string) ($in['image_size'] ?? ''), ['s', 'm', 'l'], true) ? (string) $in['image_size'] : 'm',
                'image_align' => in_array((string) ($in['image_align'] ?? ''), ['none', 'left', 'right', 'hide'], true) ? (string) $in['image_align'] : 'none',
                'author_name' => $this->nullable((string) ($in['author_name'] ?? ''), 190),
                'featured' => !empty($in['featured']) ? 1 : 0,
                'noindex' => !empty($in['noindex']) ? 1 : 0,
                'canonical_url' => $canonical === '' ? null : $canonical,
                'reading_minutes' => BlogContentProcessor::readingMinutes($body),
            ];
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_blog_article_meta WHERE content_id = ?', [$entryId]) === 1) {
                $db->update('mc_blog_article_meta', $metaFields, ['content_id' => $entryId]);
            } else {
                $db->insert('mc_blog_article_meta', $metaFields + ['content_id' => $entryId]);
            }

            $db->delete('mc_blog_article_tag', ['content_id' => $entryId]);
            foreach ($this->parseTags((string) ($in['tags'] ?? ''), $locale) as $slug => $name) {
                $db->insert('mc_blog_article_tag', ['content_id' => $entryId, 'tag_slug' => $slug, 'tag_name' => $name]);
            }
            if (array_key_exists('product_skus', $in)) {
                $this->links->replace($db, $storeId, $entryId, (string) $in['product_skus']);
            }
            return $entryId;
        });

        // SEO route (per locale) - outside the content transaction: the URL manager owns its own transactions.
        $route = $this->routes->findByEntity($storeId, $locale, SeoEntityType::BlogArticle, (string) $publicId);
        if ($route === null) {
            $route = $this->seo->ensureForCreatedEntity($storeId, $locale, SeoEntityType::BlogArticle, (string) $publicId, $title, $manualSlug !== '' ? $manualSlug : null);
        } elseif ($manualSlug !== '' && $manualSlug !== $route->slug) {
            $route = $this->seo->changeSlug($route, $manualSlug);
        }
        $this->db->update('mc_seo_route', ['indexable' => !empty($in['noindex']) ? 0 : 1], ['id' => $route->id]);

        return $entryId;
    }

    public function delete(int $storeId, int $id): void
    {
        $row = $this->db->fetchAssociative("SELECT public_id FROM mc_content_entry WHERE id = ? AND store_id = ? AND content_type = 'article'", [$id, $storeId]);
        if (!is_array($row)) {
            return;
        }
        $this->db->transactional(function (Connection $db) use ($row, $id, $storeId): void {
            $routeIds = $db->fetchFirstColumn("SELECT id FROM mc_seo_route WHERE store_id = ? AND entity_type = 'blog_article' AND entity_public_id = ?", [$storeId, $row['public_id']]);
            foreach ($routeIds as $routeId) {
                $db->executeStatement('DELETE FROM mc_seo_redirect WHERE route_id = ?', [(int) $routeId]);
            }
            $db->executeStatement("DELETE FROM mc_seo_route WHERE store_id = ? AND entity_type = 'blog_article' AND entity_public_id = ?", [$storeId, $row['public_id']]);
            $db->executeStatement("DELETE FROM mc_entity_metadata WHERE entity_type = 'article' AND entity_public_id = ?", [$row['public_id']]);
            $db->delete('mc_content_entry', ['id' => $id]);
        });
    }

    /** @return list<array{id:int,slug:string,name:string,sort_order:int,status:string,articles:int}> */
    public function categories(int $storeId, string $locale): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT c.id, c.parent_id, c.slug, c.sort_order, c.status,
                    COALESCE(t.name, (SELECT t2.name FROM mc_blog_category_translation t2 WHERE t2.category_id = c.id ORDER BY t2.locale LIMIT 1), c.slug) AS name,
                    (SELECT COUNT(*) FROM mc_blog_article_meta bm WHERE bm.category_id = c.id) AS articles
             FROM mc_blog_category c LEFT JOIN mc_blog_category_translation t ON t.category_id = c.id AND t.locale = ?
             WHERE c.store_id = ? ORDER BY c.sort_order, name",
            [$locale, $storeId],
        );
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $children = [];
        foreach ($byId as $id => $r) {
            $parent = $r['parent_id'] !== null && isset($byId[(int) $r['parent_id']]) ? (int) $r['parent_id'] : 0;
            $children[$parent][] = $id;
        }
        // The admin list is the tree: every category under its parent, indented by "depth".
        $out = [];
        $walk = function (int $parentKey, int $depth) use (&$walk, &$out, $byId, $children): void {
            foreach ($children[$parentKey] ?? [] as $id) {
                $r = $byId[$id];
                $out[] = [
                    'id' => $id, 'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null, 'depth' => $depth, 'slug' => (string) $r['slug'], 'name' => (string) $r['name'],
                    'sort_order' => (int) $r['sort_order'], 'status' => (string) $r['status'], 'articles' => (int) $r['articles'],
                ];
                if ($depth < self::MAX_CATEGORY_DEPTH) {
                    $walk($id, $depth + 1);
                }
            }
        };
        $walk(0, 0);

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function category(int $storeId, int $id, string $locale): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT c.id, c.parent_id, c.slug, c.sort_order, c.status, t.name, t.description, t.meta_title, t.meta_description
             FROM mc_blog_category c LEFT JOIN mc_blog_category_translation t ON t.category_id = c.id AND t.locale = ?
             WHERE c.id = ? AND c.store_id = ?',
            [$locale, $id, $storeId],
        );
        return is_array($row) ? $row : null;
    }

    /**
     * A parent must be a category of the same store that is not the category itself or one of its descendants (no loops)
     * and keeps the tree within MAX_CATEGORY_DEPTH levels; anything else makes the category a top-level one.
     */
    private function validParent(Connection $db, int $storeId, ?int $id, int $parentId): ?int
    {
        if ($parentId <= 0) {
            return null;
        }
        $parents = [];
        foreach ($db->fetchAllAssociative('SELECT id, parent_id FROM mc_blog_category WHERE store_id = ?', [$storeId]) as $r) {
            $parents[(int) $r['id']] = $r['parent_id'] !== null ? (int) $r['parent_id'] : null;
        }
        if (!array_key_exists($parentId, $parents)) {
            return null;
        }
        $depth = 0;
        for ($current = $parentId; $current !== null && $depth <= self::MAX_CATEGORY_DEPTH + 1; $current = $parents[$current] ?? null, ++$depth) {
            if ($id !== null && $current === $id) {
                return null;
            }
        }

        return $depth > self::MAX_CATEGORY_DEPTH ? null : $parentId;
    }

    /** @param array<string,mixed> $in */
    public function saveCategory(int $storeId, string $locale, ?int $id, array $in): int
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 190) {
            throw new InvalidArgumentException(CanonicalUiText::get('admin.blog.error.category_name'));
        }
        $status = (string) ($in['status'] ?? 'active') === 'hidden' ? 'hidden' : 'active';
        $sort = (int) ($in['sort_order'] ?? 0);
        $now = $this->now();
        $requested = trim((string) ($in['slug'] ?? ''));
        return $this->db->transactional(function (Connection $db) use ($storeId, $locale, $id, $name, $status, $sort, $now, $requested, $in): int {
            $parentId = array_key_exists('parent_id', $in) ? $this->validParent($db, $storeId, $id, (int) $in['parent_id']) : false;
            if ($id === null) {
                $slug = $this->uniqueCategorySlug($db, $storeId, $requested !== '' ? $requested : $name, $locale, null);
                $db->insert('mc_blog_category', ['store_id' => $storeId, 'parent_id' => $parentId === false ? null : $parentId, 'slug' => $slug, 'sort_order' => $sort, 'status' => $status, 'created_at' => $now, 'updated_at' => $now]);
                $id = (int) $db->lastInsertId();
            } else {
                if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_blog_category WHERE id = ? AND store_id = ?', [$id, $storeId]) !== 1) {
                    throw new InvalidArgumentException(CanonicalUiText::get('admin.blog.error.not_found'));
                }
                $update = ['sort_order' => $sort, 'status' => $status, 'updated_at' => $now];
                if ($parentId !== false) {
                    $update['parent_id'] = $parentId;
                }
                if ($requested !== '') {
                    $update['slug'] = $this->uniqueCategorySlug($db, $storeId, $requested, $locale, $id);
                }
                $db->update('mc_blog_category', $update, ['id' => $id]);
            }
            $fields = [
                'name' => $name,
                'description' => $this->nullable((string) ($in['description'] ?? ''), 5000),
                'meta_title' => $this->nullable((string) ($in['meta_title'] ?? ''), 255),
                'meta_description' => $this->nullable((string) ($in['meta_description'] ?? ''), 500),
            ];
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_blog_category_translation WHERE category_id = ? AND locale = ?', [$id, $locale]) === 1) {
                $db->update('mc_blog_category_translation', $fields, ['category_id' => $id, 'locale' => $locale]);
            } else {
                $db->insert('mc_blog_category_translation', $fields + ['category_id' => $id, 'locale' => $locale]);
            }
            return $id;
        });
    }

    public function deleteCategory(int $storeId, int $id): void
    {
        $this->db->executeStatement('DELETE FROM mc_blog_category WHERE id = ? AND store_id = ?', [$id, $storeId]);
    }

    private function uniqueCategorySlug(Connection $db, int $storeId, string $source, string $locale, ?int $exceptId): string
    {
        $base = $this->slugs->normalizeManual($this->slugs->generate($source, $locale), $locale);
        $base = $base === '' ? 'category' : mb_substr($base, 0, 100, 'UTF-8');
        $slug = $base;
        for ($i = 2; (int) $db->fetchOne('SELECT COUNT(*) FROM mc_blog_category WHERE store_id = ? AND slug = ? AND id <> ?', [$storeId, $slug, $exceptId ?? 0]) > 0; ++$i) {
            $slug = $base . '-' . $i;
        }
        return $slug;
    }

    /** @return array<string,string> slug => name */
    private function parseTags(string $raw, string $locale): array
    {
        $out = [];
        foreach (preg_split('/[,;\n]+/u', $raw) ?: [] as $part) {
            $name = trim((string) preg_replace('/\s+/u', ' ', $part));
            if ($name === '') {
                continue;
            }
            $name = mb_substr($name, 0, 120, 'UTF-8');
            $slug = mb_substr($this->slugs->generate($name, $locale), 0, 100, 'UTF-8');
            if ($slug === '' || isset($out[$slug])) {
                continue;
            }
            $out[$slug] = $name;
            if (count($out) >= self::MAX_TAGS) {
                break;
            }
        }
        return $out;
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new InvalidArgumentException(CanonicalUiText::get('admin.blog.error.date'));
        }
        return $date->format('Y-m-d H:i:s.u');
    }

    private function nullable(string $value, int $max): ?string
    {
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, $max, 'UTF-8');
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
