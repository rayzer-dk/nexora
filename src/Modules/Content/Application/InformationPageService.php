<?php

declare(strict_types=1);

namespace Commerce\Modules\Content\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Content\System\InformationPageCatalog;
use Commerce\Modules\Seo\Application\SeoPathPolicy;
use Commerce\Modules\Seo\Application\SeoUrlManager;
use Commerce\Modules\Seo\Application\SlugGenerator;
use Commerce\Modules\Seo\Contract\SeoUrlRepositoryInterface;
use Commerce\Modules\Seo\Domain\SeoEntityType;
use Commerce\Modules\Seo\System\SystemPageRouteCatalog;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Information pages (about, delivery, privacy, ...): the built-in system pages and any number of pages the merchant adds.
 *
 * A page is one mc_content_entry (content_type "page") with a text per store language (mc_content_translation) and shared
 * placement/robots settings (mc_content_page_meta). System pages keep their fixed, routed address; every other page gets an
 * address of its own per language (mc_seo_route, entity type cms_page) that is served at /{slug}; changing it leaves a 301
 * from the old address.
 */
final class InformationPageService
{
    public const STATUSES = ['draft', 'published'];
    public const GROUPS = ['company', 'buyers', 'legal', 'other'];
    /** footer_group values of the system catalogue mapped to the page groups */
    private const CATALOG_GROUP = ['company' => 'company', 'help' => 'buyers', 'legal' => 'legal'];

    public function __construct(
        private readonly Connection $db,
        private readonly PublicIdFactory $publicIds,
        private readonly SeoUrlManager $seo,
        private readonly SeoUrlRepositoryInterface $routes,
        private readonly SeoPathPolicy $paths,
        private readonly SlugGenerator $slugs,
        private readonly InformationPageCatalog $definitions,
        private readonly SystemPageRouteCatalog $systemRoutes,
        private readonly RouterInterface $router,
        #[Autowire(service: 'html_sanitizer.sanitizer.commerce.rich_text')] private readonly HtmlSanitizerInterface $sanitizer,
    ) {
    }

    /**
     * @param array{q?:string,status?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function list(int $storeId, string $locale, array $filters = []): array
    {
        $default = $this->defaultLocale($storeId);
        $where = ["ce.store_id = ?", "ce.content_type = 'page'"];
        $params = [$locale, $default, $storeId];
        $statuses = array_values(array_intersect(array_map('strval', (array) ($filters['status'] ?? [])), self::STATUSES));
        if ($statuses !== []) {
            $where[] = 'ce.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            array_push($params, ...$statuses);
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = 'COALESCE(ct.title, cd.title) LIKE ?';
            $params[] = '%' . addcslashes($q, '%_\\') . '%';
        }
        $rows = $this->db->fetchAllAssociative(
            "SELECT ce.id, ce.public_id, ce.system_key, ce.status, ce.updated_at, COALESCE(ct.title, cd.title, '') AS title,
                    COALESCE(pm.show_in_footer, 1) AS show_in_footer, COALESCE(pm.show_in_menu, 0) AS show_in_menu, pm.page_group, COALESCE(pm.sort_order, 100) AS sort_order, COALESCE(pm.noindex, 0) AS noindex,
                    (SELECT GROUP_CONCAT(t.locale) FROM mc_content_translation t WHERE t.content_id = ce.id AND t.title <> '') AS locales
             FROM mc_content_entry ce
             LEFT JOIN mc_content_translation ct ON ct.content_id = ce.id AND ct.locale = ?
             LEFT JOIN mc_content_translation cd ON cd.content_id = ce.id AND cd.locale = ?
             LEFT JOIN mc_content_page_meta pm ON pm.content_id = ce.id
             WHERE " . implode(' AND ', $where) . '
             ORDER BY (ce.system_key IS NULL), sort_order, ce.id',
            $params,
        );
        $items = [];
        foreach ($rows as $row) {
            $systemKey = $row['system_key'] !== null ? (string) $row['system_key'] : null;
            $publicId = Uuid::fromBinary((string) $row['public_id'])->toRfc4122();
            $items[] = [
                'id' => (int) $row['id'],
                'ref' => $systemKey ?? (string) $row['id'],
                'public_id' => $publicId,
                'system_key' => $systemKey,
                'is_system' => $systemKey !== null,
                'title' => (string) $row['title'],
                'status' => (string) $row['status'],
                'updated_at' => substr((string) $row['updated_at'], 0, 16),
                'show_in_footer' => (bool) $row['show_in_footer'],
                'show_in_menu' => (bool) $row['show_in_menu'],
                'noindex' => (bool) $row['noindex'],
                'sort_order' => (int) $row['sort_order'],
                'locales' => $row['locales'] !== null && $row['locales'] !== '' ? explode(',', (string) $row['locales']) : [],
                'url' => $this->publicPath($storeId, $systemKey, $publicId, $locale, $default),
            ];
        }

        return $items;
    }

    /**
     * One page for the editor in the given language (an empty text set when that language has no translation yet).
     *
     * @return array<string,mixed>|null
     */
    public function find(int $storeId, string $ref, string $locale): ?array
    {
        $entry = $this->entry($storeId, $ref);
        if ($entry === null) {
            return null;
        }
        $default = $this->defaultLocale($storeId);
        $id = (int) $entry['id'];
        $publicId = Uuid::fromBinary((string) $entry['public_id'])->toRfc4122();
        $systemKey = $entry['system_key'] !== null ? (string) $entry['system_key'] : null;
        $translation = $this->db->fetchAssociative('SELECT title, excerpt, body_html, meta_title, meta_description FROM mc_content_translation WHERE content_id = ? AND locale = ?', [$id, $locale]);
        $defaultText = $locale === $default ? null : $this->db->fetchAssociative('SELECT title, excerpt, body_html, meta_title, meta_description FROM mc_content_translation WHERE content_id = ? AND locale = ?', [$id, $default]);
        $meta = $this->db->fetchAssociative(
            'SELECT pm.page_group, pm.show_in_footer, pm.show_in_menu, pm.sort_order, pm.noindex, pm.canonical_url, pm.og_asset_id, ma.storage_key AS og_key
             FROM mc_content_page_meta pm LEFT JOIN mc_media_asset ma ON ma.id = pm.og_asset_id WHERE pm.content_id = ?',
            [$id],
        ) ?: [];
        $route = $systemKey === null ? $this->routes->findByEntity($storeId, $locale, SeoEntityType::CmsPage, $publicId) : null;
        $done = [];
        foreach ($this->db->fetchAllAssociative('SELECT locale, title, body_html FROM mc_content_translation WHERE content_id = ?', [$id]) as $row) {
            $done[(string) $row['locale']] = trim((string) $row['title']) !== '' && trim((string) ($row['body_html'] ?? '')) !== '';
        }

        return [
            'id' => $id,
            'ref' => $systemKey ?? (string) $id,
            'public_id' => $publicId,
            'system_key' => $systemKey,
            'is_system' => $systemKey !== null,
            'status' => (string) $entry['status'],
            'has_translation' => is_array($translation),
            'title' => (string) ($translation['title'] ?? ''),
            'excerpt' => (string) ($translation['excerpt'] ?? ''),
            'body_html' => (string) ($translation['body_html'] ?? ''),
            'meta_title' => (string) ($translation['meta_title'] ?? ''),
            'meta_description' => (string) ($translation['meta_description'] ?? ''),
            'default_text' => $defaultText === false || $defaultText === null ? null : array_map(static fn ($v): string => (string) ($v ?? ''), $defaultText),
            'page_group' => (string) ($meta['page_group'] ?? $this->defaultGroup($systemKey)),
            'show_in_footer' => (bool) ($meta['show_in_footer'] ?? true),
            'show_in_menu' => (bool) ($meta['show_in_menu'] ?? false),
            'sort_order' => (int) ($meta['sort_order'] ?? 100),
            'noindex' => (bool) ($meta['noindex'] ?? false),
            'canonical_url' => (string) ($meta['canonical_url'] ?? ''),
            'og_asset_id' => isset($meta['og_asset_id']) ? (int) $meta['og_asset_id'] : 0,
            'og_url' => isset($meta['og_key']) ? '/media/' . ltrim((string) $meta['og_key'], '/') : '',
            'slug' => $route?->slug ?? '',
            'path' => $this->publicPath($storeId, $systemKey, $publicId, $locale, $default),
            'slug_prefix' => '',
            'translated' => $done,
        ];
    }

    /**
     * Creates or updates a page in one language. Returns the entry id.
     *
     * @param array<string,mixed> $in
     */
    public function save(int $storeId, string $locale, ?int $id, array $in, string $authorSubject): int
    {
        if ($id !== null && empty($in['placement_present'])) {
            // A client that sends only the texts (an API call, an old form) must not reset placement, robots and image.
            $kept = $this->db->fetchAssociative('SELECT page_group, show_in_footer, show_in_menu, sort_order, noindex, canonical_url, og_asset_id FROM mc_content_page_meta WHERE content_id = ?', [$id]);
            if (is_array($kept)) {
                $in = array_merge($in, ['page_group' => $kept['page_group'], 'show_in_footer' => $kept['show_in_footer'], 'show_in_menu' => $kept['show_in_menu'], 'sort_order' => $kept['sort_order'], 'robots' => $kept['noindex'] ? 'noindex' : 'index', 'canonical_url' => $kept['canonical_url'] ?? '', 'og_asset_id' => $kept['og_asset_id'] ?? 0]);
            }
        }
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '' || mb_strlen($title, 'UTF-8') > 255) {
            throw new InvalidArgumentException(CanonicalUiText::get('admin.pages.error.title'));
        }
        $status = (string) ($in['status'] ?? 'draft');
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(CanonicalUiText::get('admin.pages.error.status'));
        }
        $body = trim((string) ($in['body_html'] ?? ''));
        $body = $body === '' ? '' : trim($this->sanitizer->sanitize($body));
        if ($status === 'published' && $body === '') {
            throw new InvalidArgumentException(CanonicalUiText::get('admin.pages.error.body'));
        }
        $canonical = trim((string) ($in['canonical_url'] ?? ''));
        if ($canonical !== '' && preg_match('#^https?://[^\s"<>]+$#i', $canonical) !== 1) {
            throw new InvalidArgumentException(CanonicalUiText::get('admin.pages.error.canonical'));
        }
        $group = (string) ($in['page_group'] ?? 'company');
        $group = in_array($group, self::GROUPS, true) ? $group : 'other';
        $ogId = (int) ($in['og_asset_id'] ?? 0);
        if ($ogId > 0 && (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_store_media_asset WHERE store_id = ? AND asset_id = ?', [$storeId, $ogId]) !== 1) {
            throw new InvalidArgumentException(CanonicalUiText::get('admin.pages.error.og_image'));
        }
        $now = gmdate('Y-m-d H:i:s.u');

        $existing = $id !== null ? $this->db->fetchAssociative("SELECT id, public_id, system_key FROM mc_content_entry WHERE id = ? AND store_id = ? AND content_type = 'page'", [$id, $storeId]) : null;
        if ($id !== null && !is_array($existing)) {
            throw new InvalidArgumentException(CanonicalUiText::get('admin.pages.error.not_found'));
        }
        $isSystem = is_array($existing) && $existing['system_key'] !== null;
        $manualSlug = trim((string) ($in['slug'] ?? ''));
        $routePlan = null;
        if (!$isSystem) {
            $publicIdForSlug = is_array($existing) ? Uuid::fromBinary((string) $existing['public_id'])->toRfc4122() : null;
            $routePlan = $this->planSlug($storeId, $locale, $publicIdForSlug, $manualSlug, $title);
        }

        $publicId = null;
        $entryId = $this->db->transactional(function (Connection $db) use ($storeId, $locale, $existing, $title, $status, $body, $in, $canonical, $group, $ogId, $now, $authorSubject, &$publicId): int {
            if (!is_array($existing)) {
                $uuid = $this->publicIds->generate();
                $publicId = $uuid->toRfc4122();
                $db->insert('mc_content_entry', [
                    'public_id' => $uuid->toBinary(), 'store_id' => $storeId, 'content_type' => 'page', 'system_key' => null,
                    'status' => $status, 'author_subject' => mb_substr($authorSubject, 0, 190, 'UTF-8'),
                    'published_at' => $status === 'published' ? $now : null, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $entryId = (int) $db->lastInsertId();
            } else {
                $entryId = (int) $existing['id'];
                $publicId = Uuid::fromBinary((string) $existing['public_id'])->toRfc4122();
                $previous = (string) $db->fetchOne('SELECT status FROM mc_content_entry WHERE id = ? FOR UPDATE', [$entryId]);
                $db->update('mc_content_entry', [
                    'status' => $status,
                    'published_at' => $status === 'published' ? ($previous === 'published' ? $db->fetchOne('SELECT published_at FROM mc_content_entry WHERE id = ?', [$entryId]) : $now) : null,
                    'updated_at' => $now,
                ], ['id' => $entryId]);
            }
            $fields = [
                'title' => $title,
                'excerpt' => $this->nullable((string) ($in['excerpt'] ?? ''), 1000),
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
            $meta = [
                'page_group' => $group,
                'show_in_footer' => !empty($in['show_in_footer']) ? 1 : 0,
                'show_in_menu' => !empty($in['show_in_menu']) ? 1 : 0,
                'sort_order' => max(0, min(9999, (int) ($in['sort_order'] ?? 100))),
                'noindex' => (string) ($in['robots'] ?? (!empty($in['noindex']) ? 'noindex' : 'index')) === 'noindex' ? 1 : 0,
                'canonical_url' => $canonical === '' ? null : $canonical,
                'og_asset_id' => $ogId > 0 ? $ogId : null,
            ];
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_content_page_meta WHERE content_id = ?', [$entryId]) === 1) {
                $db->update('mc_content_page_meta', $meta, ['content_id' => $entryId]);
            } else {
                $db->insert('mc_content_page_meta', $meta + ['content_id' => $entryId]);
            }

            return $entryId;
        });

        if ($routePlan !== null) {
            $this->applySlug($storeId, $locale, (string) $publicId, $routePlan, $title, (string) (($in['robots'] ?? '') === 'noindex' ? 'noindex' : 'index'));
        }

        return $entryId;
    }

    /** Flips draft/published without touching anything else. Returns the new status. */
    public function toggle(int $storeId, int $id): string
    {
        $row = $this->db->fetchAssociative("SELECT status FROM mc_content_entry WHERE id = ? AND store_id = ? AND content_type = 'page'", [$id, $storeId]);
        if (!is_array($row)) {
            throw new InvalidArgumentException(CanonicalUiText::get('admin.pages.error.not_found'));
        }
        $next = $row['status'] === 'published' ? 'draft' : 'published';
        if ($next === 'published' && (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_content_translation WHERE content_id = ? AND TRIM(COALESCE(body_html, '')) <> ''", [$id]) < 1) {
            throw new InvalidArgumentException(CanonicalUiText::get('admin.pages.error.body'));
        }
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->update('mc_content_entry', ['status' => $next, 'published_at' => $next === 'published' ? $now : null, 'updated_at' => $now], ['id' => $id]);

        return $next;
    }

    /** Copies a page (every language) as a draft with fresh addresses. Returns the id of the copy. */
    public function duplicate(int $storeId, int $id, string $authorSubject): int
    {
        $entry = $this->db->fetchAssociative("SELECT id FROM mc_content_entry WHERE id = ? AND store_id = ? AND content_type = 'page'", [$id, $storeId]);
        if (!is_array($entry)) {
            throw new InvalidArgumentException(CanonicalUiText::get('admin.pages.error.not_found'));
        }
        $now = gmdate('Y-m-d H:i:s.u');
        $uuid = $this->publicIds->generate();
        $copyId = $this->db->transactional(function (Connection $db) use ($storeId, $id, $uuid, $now, $authorSubject): int {
            $db->insert('mc_content_entry', [
                'public_id' => $uuid->toBinary(), 'store_id' => $storeId, 'content_type' => 'page', 'system_key' => null,
                'status' => 'draft', 'author_subject' => mb_substr($authorSubject, 0, 190, 'UTF-8'), 'published_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $copyId = (int) $db->lastInsertId();
            $suffix = ' ' . CanonicalUiText::get('admin.pages.copy_suffix');
            foreach ($db->fetchAllAssociative('SELECT locale, title, excerpt, body_html, meta_title, meta_description FROM mc_content_translation WHERE content_id = ?', [$id]) as $row) {
                $row['title'] = mb_substr((string) $row['title'], 0, 255 - mb_strlen($suffix, 'UTF-8'), 'UTF-8') . $suffix;
                $db->insert('mc_content_translation', $row + ['content_id' => $copyId, 'created_at' => $now, 'updated_at' => $now]);
            }
            $meta = $db->fetchAssociative('SELECT page_group, show_in_footer, show_in_menu, sort_order, noindex, canonical_url, og_asset_id FROM mc_content_page_meta WHERE content_id = ?', [$id]);
            if (is_array($meta)) {
                $meta['canonical_url'] = null;
                $db->insert('mc_content_page_meta', $meta + ['content_id' => $copyId]);
            }

            return $copyId;
        });
        foreach ($this->db->fetchAllAssociative('SELECT locale, title FROM mc_content_translation WHERE content_id = ?', [$copyId]) as $row) {
            try {
                $this->seo->ensureForCreatedEntity($storeId, (string) $row['locale'], SeoEntityType::CmsPage, $uuid->toRfc4122(), (string) $row['title']);
            } catch (\Throwable) {
                // A title that cannot become an address leaves the copy without one; the editor asks for it before publishing.
            }
        }

        return $copyId;
    }

    /** Only merchant-added pages can be removed; the system pages are edited or hidden instead. */
    public function delete(int $storeId, int $id): void
    {
        $row = $this->db->fetchAssociative("SELECT public_id, system_key FROM mc_content_entry WHERE id = ? AND store_id = ? AND content_type = 'page'", [$id, $storeId]);
        if (!is_array($row)) {
            return;
        }
        if ($row['system_key'] !== null) {
            throw new InvalidArgumentException(CanonicalUiText::get('admin.pages.error.system_delete'));
        }
        $this->db->transactional(function (Connection $db) use ($row, $id, $storeId): void {
            $db->executeStatement("DELETE FROM mc_seo_route WHERE store_id = ? AND entity_type = 'cms_page' AND entity_public_id = ?", [$storeId, $row['public_id']]);
            $db->delete('mc_content_entry', ['id' => $id]);
        });
    }

    /** @return list<array{title:string,url:string,group:string}> published custom pages for a storefront placement ("footer" or "menu") */
    public function placed(int $storeId, string $locale, string $placement): array
    {
        $column = $placement === 'menu' ? 'pm.show_in_menu' : 'pm.show_in_footer';
        $default = $this->defaultLocale($storeId);
        $rows = $this->db->fetchAllAssociative(
            "SELECT ce.public_id, COALESCE(ct.title, cd.title) AS title, pm.page_group
             FROM mc_content_entry ce
             JOIN mc_content_page_meta pm ON pm.content_id = ce.id
             LEFT JOIN mc_content_translation ct ON ct.content_id = ce.id AND ct.locale = ?
             LEFT JOIN mc_content_translation cd ON cd.content_id = ce.id AND cd.locale = ?
             WHERE ce.store_id = ? AND ce.content_type = 'page' AND ce.system_key IS NULL AND ce.status = 'published' AND $column = 1
             ORDER BY pm.sort_order, ce.id",
            [$locale, $default, $storeId],
        );
        $out = [];
        foreach ($rows as $row) {
            $publicId = Uuid::fromBinary((string) $row['public_id'])->toRfc4122();
            $url = $this->publicPath($storeId, null, $publicId, $locale, $default);
            if ($url !== null && trim((string) $row['title']) !== '') {
                $out[] = ['title' => (string) $row['title'], 'url' => $url, 'group' => (string) $row['page_group']];
            }
        }

        return $out;
    }

    /**
     * Validates the requested address before anything is written. An explicit address that is taken is an error the merchant
     * can fix; an automatic one simply gets a numeric suffix.
     *
     * @return array{slug:?string,manual:bool}
     */
    public function planSlug(int $storeId, string $locale, ?string $publicId, string $manualSlug, string $title): array
    {
        $current = $publicId !== null ? $this->routes->findByEntity($storeId, $locale, SeoEntityType::CmsPage, $publicId) : null;
        if ($manualSlug === '') {
            if ($current === null) {
                try {
                    $this->slugs->generate($title, $locale);
                } catch (InvalidArgumentException) {
                    throw new InvalidArgumentException(CanonicalUiText::get('admin.pages.error.slug_from_title'));
                }
            }

            return ['slug' => null, 'manual' => false];
        }
        if ($current !== null && $manualSlug === $current->slug) {
            return ['slug' => null, 'manual' => false];
        }
        try {
            $slug = $this->slugs->normalizeManual($manualSlug, $locale);
            $path = $this->paths->path(SeoEntityType::CmsPage, $slug, $locale);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(CanonicalUiText::get('admin.pages.error.slug_invalid'));
        }
        if ($current !== null && $current->slug === $slug) {
            return ['slug' => null, 'manual' => false];
        }
        if ($this->pathTaken($storeId, $locale, $path, $current?->id)) {
            throw new InvalidArgumentException(CanonicalUiText::get('admin.pages.error.slug_taken', ['slug' => $slug]));
        }

        return ['slug' => $slug, 'manual' => true];
    }

    /** True when something already answers at this address: another page/product/article, a redirect, or a fixed application route. */
    public function pathTaken(int $storeId, string $locale, string $path, ?int $exceptRouteId = null): bool
    {
        if ($this->routes->pathIsReserved($storeId, $locale, $path, $exceptRouteId)) {
            return true;
        }
        $context = $this->router->getContext();
        $method = $context->getMethod();
        $context->setMethod('GET');
        try {
            $match = $this->router->match('/' . $path);

            return ($match['_route'] ?? '') !== 'storefront_seo_entity';
        } catch (MethodNotAllowedException) {
            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            $context->setMethod($method);
        }
    }

    /** @param array{slug:?string,manual:bool} $plan */
    private function applySlug(int $storeId, string $locale, string $publicId, array $plan, string $title, string $robots): void
    {
        $route = $this->routes->findByEntity($storeId, $locale, SeoEntityType::CmsPage, $publicId);
        if ($route === null) {
            $route = $this->seo->ensureForCreatedEntity($storeId, $locale, SeoEntityType::CmsPage, $publicId, $title, $plan['slug']);
        } elseif ($plan['slug'] !== null) {
            $route = $this->seo->changeSlug($route, $plan['slug']);
        }
        $this->db->update('mc_seo_route', ['indexable' => $robots === 'noindex' ? 0 : 1], ['id' => $route->id]);
    }

    /** @return array<string,mixed>|null */
    private function entry(int $storeId, string $ref): ?array
    {
        $row = ctype_digit($ref)
            ? $this->db->fetchAssociative("SELECT id, public_id, system_key, status FROM mc_content_entry WHERE id = ? AND store_id = ? AND content_type = 'page'", [(int) $ref, $storeId])
            : $this->db->fetchAssociative("SELECT id, public_id, system_key, status FROM mc_content_entry WHERE system_key = ? AND store_id = ? AND content_type = 'page'", [$ref, $storeId]);

        return is_array($row) ? $row : null;
    }

    private function publicPath(int $storeId, ?string $systemKey, string $publicId, string $locale, string $default): ?string
    {
        if ($systemKey !== null) {
            try {
                return '/' . $this->systemRoutes->route($this->definitions->get($systemKey)->routeKey, $locale)->path;
            } catch (\Throwable) {
                return null;
            }
        }
        $route = $this->routes->findByEntity($storeId, $locale, SeoEntityType::CmsPage, $publicId) ?? $this->routes->findByEntity($storeId, $default, SeoEntityType::CmsPage, $publicId);

        return $route !== null ? '/' . $route->path : null;
    }

    private function defaultGroup(?string $systemKey): string
    {
        if ($systemKey === null) {
            return 'company';
        }
        try {
            return self::CATALOG_GROUP[$this->definitions->get($systemKey)->footerGroup] ?? 'other';
        } catch (\Throwable) {
            return 'other';
        }
    }

    private function defaultLocale(int $storeId): string
    {
        return (string) $this->db->fetchOne('SELECT default_locale FROM mc_store WHERE id = ?', [$storeId]);
    }

    private function nullable(string $value, int $max): ?string
    {
        $value = trim(strip_tags($value));

        return $value === '' ? null : mb_substr($value, 0, $max, 'UTF-8');
    }
}
