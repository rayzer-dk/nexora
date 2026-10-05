<?php

declare(strict_types=1);

namespace Commerce\Modules\Quality\Application;

use Commerce\Core\Configuration\SystemSettingStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Daily check of the shop's own content: the menu, pages, articles, product descriptions and the storefront settings are
 * searched for links to pages that no longer exist and for pictures whose file is gone. Only the shop itself is checked
 * (no network): external addresses are counted but not opened.
 */
final class LinkCheckService
{
    private const LAST_KEY = 'linkcheck.last';

    /** @var array<string,bool>|null */
    private ?array $known = null;

    /** @var list<string>|null */
    private ?array $patterns = null;

    /** @var list<string> */
    private array $prefixes = [];

    public function __construct(
        private readonly Connection $db,
        private readonly RouterInterface $router,
        private readonly SystemSettingStore $store,
        private readonly \Commerce\Modules\Appearance\Infrastructure\StorefrontPresentationSettings $presentation,
        private readonly string $projectDir,
    ) {
    }

    /** @return array{at:string,documents:int,links:int,images:int,external:int,issues:int,seconds:float} */
    public function run(): array
    {
        $started = microtime(true);
        $startedAt = gmdate('Y-m-d H:i:s.u');
        $this->prefixes = array_map('strtolower', array_filter($this->db->fetchFirstColumn("SELECT DISTINCT url_prefix FROM mc_store_locale WHERE url_prefix IS NOT NULL AND url_prefix<>''")));
        $stats = ['documents' => 0, 'links' => 0, 'images' => 0, 'external' => 0];
        $found = [];

        foreach ($this->sources() as [$type, $label, $editUrl, $locale, $html, $urls]) {
            ++$stats['documents'];
            foreach ($this->extract((string) $html) as [$kind, $target]) {
                $this->evaluate($stats, $found, $type, (string) $label, (string) $editUrl, $locale, $kind, $target);
            }
            foreach ($urls as [$kind, $target]) {
                $this->evaluate($stats, $found, $type, (string) $label, (string) $editUrl, $locale, $kind, $target);
            }
        }

        $this->db->transactional(function (Connection $db) use ($found, $startedAt): void {
            foreach ($found as $hash => $f) {
                $db->executeStatement(
                    'INSERT INTO mc_link_check_issue (issue_hash,source_type,source_label,source_url,kind,target,reason,locale,ignored,first_seen_at,last_seen_at) VALUES (?,?,?,?,?,?,?,?,0,?,?) ON DUPLICATE KEY UPDATE source_label=VALUES(source_label),source_url=VALUES(source_url),reason=VALUES(reason),last_seen_at=VALUES(last_seen_at)',
                    [$hash, $f['type'], mb_substr($f['label'], 0, 255), mb_substr($f['url'], 0, 255), $f['kind'], mb_substr($f['target'], 0, 1000), $f['reason'], $f['locale'], $startedAt, $startedAt],
                );
            }
            $db->executeStatement('DELETE FROM mc_link_check_issue WHERE last_seen_at<?', [$startedAt]);
        });

        $result = ['at' => gmdate('c'), 'documents' => $stats['documents'], 'links' => $stats['links'], 'images' => $stats['images'], 'external' => $stats['external'], 'issues' => count($found), 'seconds' => round(microtime(true) - $started, 2)];
        $this->store->setArray(self::LAST_KEY, $result);

        return $result;
    }

    /** @return array{at:string,documents:int,links:int,images:int,external:int,issues:int,seconds:float}|null */
    public function last(): ?array
    {
        $v = $this->store->getArray(self::LAST_KEY);

        return is_array($v) && isset($v['at']) ? $v : null;
    }

    /** @return list<array<string,mixed>> */
    public function issues(bool $withIgnored = false): array
    {
        return $this->db->fetchAllAssociative('SELECT id,source_type,source_label,source_url,kind,target,reason,locale,ignored,first_seen_at FROM mc_link_check_issue' . ($withIgnored ? '' : ' WHERE ignored=0') . ' ORDER BY kind,source_type,id LIMIT 1000');
    }

    public function openCount(): int
    {
        try {
            return (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_link_check_issue WHERE ignored=0');
        } catch (\Throwable) {
            return 0;
        }
    }

    public function setIgnored(int $id, bool $ignored): void
    {
        $this->db->update('mc_link_check_issue', ['ignored' => $ignored ? 1 : 0], ['id' => $id]);
    }

    /** @return iterable<array{0:string,1:string,2:string,3:?string,4:string,5:list<array{0:string,1:string}>}> */
    private function sources(): iterable
    {
        $gen = fn (string $name, array $params = []): string => $this->router->generate($name, $params, UrlGeneratorInterface::ABSOLUTE_PATH);

        foreach ($this->db->fetchAllAssociative("SELECT i.id,i.url,i.item_type,COALESCE(t.label,'') label FROM mc_navigation_item i LEFT JOIN mc_navigation_item_translation t ON t.navigation_item_id=i.id WHERE i.status='active' AND i.url IS NOT NULL AND i.url<>'' GROUP BY i.id,i.url,i.item_type,t.label") as $r) {
            yield ['menu', (string) $r['label'] !== '' ? (string) $r['label'] : (string) $r['url'], '/admin/appearance/navigation', null, '', [['link', (string) $r['url']]]];
        }

        $rows = $this->db->fetchAllAssociative("SELECT e.id,e.content_type,e.system_key,e.public_id,t.locale,t.title,t.body_html FROM mc_content_entry e JOIN mc_content_translation t ON t.content_id=e.id WHERE e.status='published' AND t.body_html<>''");
        foreach ($rows as $r) {
            $isPage = (string) $r['content_type'] === 'page';
            $ref = $r['system_key'] !== null ? (string) $r['system_key'] : \Symfony\Component\Uid\Uuid::fromBinary((string) $r['public_id'])->toRfc4122();
            $edit = $isPage ? $gen('admin_content_page_edit', ['ref' => $ref]) : $gen('admin_content_blog_edit', ['id' => (int) $r['id']]);
            yield [$isPage ? 'page' : 'article', (string) $r['title'], $edit, (string) $r['locale'], (string) $r['body_html'], []];
        }

        foreach ($this->db->fetchAllAssociative("SELECT p.public_id,t.locale,t.name,CONCAT(COALESCE(t.short_description,''),' ',COALESCE(t.description,'')) html FROM mc_product p JOIN mc_product_translation t ON t.product_id=p.id WHERE p.status='published' AND (t.description LIKE '%href=%' OR t.description LIKE '%src=%' OR t.short_description LIKE '%href=%' OR t.short_description LIKE '%src=%')") as $r) {
            yield ['product', (string) $r['name'], $gen('admin_catalog_product_edit', ['publicId' => \Symfony\Component\Uid\Uuid::fromBinary((string) $r['public_id'])->toRfc4122()]), (string) $r['locale'], (string) $r['html'], []];
        }

        foreach ($this->db->fetchFirstColumn("SELECT id FROM mc_store WHERE status='active'") as $storeId) {
            try {
                $raw = $this->presentation->getRaw((int) $storeId);
            } catch (\Throwable) {
                continue;
            }
            $urls = [];
            $this->walk($raw, '', $urls);
            if ($urls !== []) {
                yield ['storefront', 'storefront', '/admin/appearance/storefront', null, '', $urls];
            }
        }
    }

    /** Strings of the storefront settings that look like a link or a picture. @param list<array{0:string,1:string}> $urls */
    private function walk(mixed $value, string $key, array &$urls): void
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $this->walk($v, is_string($k) ? $k : $key, $urls);
            }

            return;
        }
        if (!is_string($value) || $value === '' || mb_strlen($value) > 1000) {
            return;
        }
        if (preg_match('/(?:image|img|src|photo|poster|icon_url|logo)/i', $key) === 1 && str_starts_with($value, '/')) {
            $urls[] = ['image', $value];
        } elseif (preg_match('/(?:url|link|href)/i', $key) === 1 && (str_starts_with($value, '/') || preg_match('#^https?://#i', $value) === 1)) {
            $urls[] = ['link', $value];
        }
    }

    /** @return list<array{0:string,1:string}> */
    private function extract(string $html): array
    {
        if ($html === '' || (!str_contains($html, 'href') && !str_contains($html, 'src'))) {
            return [];
        }
        $out = [];
        if (preg_match_all('/<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1/is', $html, $m)) {
            foreach ($m[2] as $u) {
                $out[] = ['link', html_entity_decode($u, ENT_QUOTES | ENT_HTML5)];
            }
        }
        if (preg_match_all('/<img\b[^>]*?\bsrc\s*=\s*(["\'])(.*?)\1/is', $html, $m)) {
            foreach ($m[2] as $u) {
                $out[] = ['image', html_entity_decode($u, ENT_QUOTES | ENT_HTML5)];
            }
        }

        return $out;
    }

    /** @param array<string,int> $stats @param array<string,array<string,mixed>> $found */
    private function evaluate(array &$stats, array &$found, string $type, string $label, string $editUrl, ?string $locale, string $kind, string $target): void
    {
        $target = trim($target);
        if ($target === '' || str_starts_with($target, '#') || preg_match('#^(?:mailto:|tel:|javascript:|data:|sms:|viber:|tg:)#i', $target) === 1) {
            return;
        }
        if (preg_match('#^(?:https?:)?//#i', $target) === 1) {
            ++$stats['external'];

            return;
        }
        if (!str_starts_with($target, '/')) {
            return;
        }
        ++$stats[$kind === 'image' ? 'images' : 'links'];
        $reason = $kind === 'image' ? $this->imageProblem($target) : ($this->pageExists($target) ? null : 'not_found');
        if ($reason === null) {
            return;
        }
        $hash = sha1($type . '|' . $editUrl . '|' . $kind . '|' . $target . '|' . ($locale ?? ''));
        $found[$hash] = ['type' => $type, 'label' => $label, 'url' => $editUrl, 'kind' => $kind, 'target' => $target, 'reason' => $reason, 'locale' => $locale];
    }

    private function imageProblem(string $target): ?string
    {
        $path = (string) parse_url($target, PHP_URL_PATH);
        if (str_starts_with($path, '/media/')) {
            $key = ltrim(substr($path, 7), '/');
            if (str_starts_with($key, 'cache/')) {
                return null;
            }

            return $this->db->fetchOne('SELECT 1 FROM mc_media_asset WHERE storage_key=? LIMIT 1', [$key]) !== false ? null : 'file_missing';
        }
        $file = $this->projectDir . '/public' . rawurldecode($path);
        if (str_contains($path, '..')) {
            return 'file_missing';
        }

        return is_file($file) ? null : 'file_missing';
    }

    private function pageExists(string $target): bool
    {
        $path = '/' . trim((string) parse_url($target, PHP_URL_PATH), '/');
        $path = rawurldecode(strtolower($path));
        if ($path === '/') {
            return true;
        }
        $this->load();
        foreach ($this->variants($path) as $candidate) {
            if (isset($this->known[$candidate])) {
                return true;
            }
            foreach ($this->patterns ?? [] as $regex) {
                if (preg_match($regex, $candidate) === 1) {
                    return true;
                }
            }
        }

        return str_starts_with($path, '/media/') || str_starts_with($path, '/build/') || str_starts_with($path, '/assets/') || is_file($this->projectDir . '/public' . $path);
    }

    /** @return list<string> the path itself and the path without a language prefix */
    private function variants(string $path): array
    {
        $out = [$path];
        $segments = explode('/', ltrim($path, '/'), 2);
        if (isset($segments[1]) && (in_array($segments[0], $this->prefixes, true) || preg_match('/^[a-z]{2}$/', $segments[0]) === 1)) {
            $out[] = '/' . $segments[1];
        }

        return $out;
    }

    private function load(): void
    {
        if ($this->known !== null) {
            return;
        }
        $this->known = [];
        foreach ($this->db->fetchFirstColumn('SELECT path FROM mc_seo_route') as $p) {
            $this->known['/' . trim(strtolower((string) $p), '/')] = true;
        }
        foreach ($this->db->fetchFirstColumn('SELECT source_path FROM mc_seo_redirect') as $p) {
            $this->known['/' . trim(strtolower((string) $p), '/')] = true;
        }
        try {
            foreach ($this->db->fetchFirstColumn('SELECT source_path FROM mc_custom_redirect WHERE enabled=1') as $p) {
                $this->known['/' . trim(strtolower((string) $p), '/')] = true;
            }
        } catch (\Throwable) {
        }
        $this->patterns = [];
        foreach ($this->router->getRouteCollection() as $route) {
            $path = $route->getPath();
            if (str_starts_with($path, '/admin') || $path === '/') {
                continue;
            }
            // A route that is only a variable matches any address; it counts only when its requirement lists the allowed values.
            if (preg_match('#^/\{([^}]+)\}$#', $path, $single) === 1) {
                $requirement = (string) $route->getRequirement($single[1]);
                if ($requirement === '' || preg_match('/[.\[*+]/', $requirement) === 1) {
                    continue;
                }
            }
            if (!str_contains($path, '{')) {
                $this->known[strtolower('/' . trim($path, '/'))] = true;
                continue;
            }
            $this->patterns[] = $route->compile()->getRegex() . 'i';
        }
    }
}
