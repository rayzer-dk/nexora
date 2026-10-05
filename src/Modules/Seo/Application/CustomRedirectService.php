<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Application;

use Doctrine\DBAL\Connection;

/**
 * Own redirects: an old address that no longer exists is sent to a new one (a typical case after moving from another
 * shop engine), and the addresses that visitors could not find are counted so they can be redirected.
 */
final class CustomRedirectService
{
    public const MAX_NOT_FOUND_ROWS = 3000;

    public function __construct(private readonly Connection $db)
    {
    }

    /** "/Old-Page/?a=1" → "/old-page"; the query string and the trailing slash do not matter. */
    public static function normalizePath(string $path): string
    {
        $path = (string) parse_url(trim($path), PHP_URL_PATH);
        $path = '/' . trim($path, '/');

        return mb_strtolower(mb_substr($path, 0, 500));
    }

    public static function normalizeTarget(string $target): ?string
    {
        $target = trim($target);
        if ($target === '' || mb_strlen($target) > 1000 || preg_match('/[\x00-\x1F\x7F\s]/u', $target) === 1) {
            return null;
        }
        if (str_starts_with($target, '/') && !str_starts_with($target, '//')) {
            return $target;
        }

        return preg_match('#^https?://[^/\s]+#i', $target) === 1 ? $target : null;
    }

    /** @return array{id:int,target_url:string,status_code:int}|null */
    public function find(string $path): ?array
    {
        $row = $this->db->fetchAssociative('SELECT id,target_url,status_code FROM mc_custom_redirect WHERE source_hash=? AND enabled=1', [sha1(self::normalizePath($path))]);

        return is_array($row) ? ['id' => (int) $row['id'], 'target_url' => (string) $row['target_url'], 'status_code' => (int) $row['status_code']] : null;
    }

    public function hit(int $id): void
    {
        $this->db->executeStatement('UPDATE mc_custom_redirect SET hit_count=hit_count+1,last_hit_at=UTC_TIMESTAMP(6) WHERE id=?', [$id]);
    }

    public function add(string $source, string $target, int $code = 301): void
    {
        $path = self::normalizePath($source);
        $target = self::normalizeTarget($target);
        if ($path === '/' || $target === null) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.redirects.invalid'));
        }
        if (self::normalizePath($target) === $path && str_starts_with($target, '/')) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.redirects.loop'));
        }
        $code = in_array($code, [301, 302, 307, 308], true) ? $code : 301;
        $this->db->executeStatement(
            'INSERT INTO mc_custom_redirect (source_hash,source_path,target_url,status_code,enabled,created_at) VALUES (?,?,?,?,1,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE target_url=VALUES(target_url),status_code=VALUES(status_code),enabled=1',
            [sha1($path), $path, $target, $code],
        );
        $this->db->delete('mc_not_found_log', ['path_hash' => sha1($path)]);
    }

    /** One redirect per line: "old;new" or "old;new;302" (comma or tab also work). @return array{added:int,skipped:int} */
    public function import(string $text): array
    {
        $added = 0;
        $skipped = 0;
        foreach (preg_split('/\R/', $text) ?: [] as $i => $line) {
            if ($i >= 5000) {
                break;
            }
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/[;\t,](?=\s*(?:\/|https?:))|[;\t]/', $line, 3) ?: [];
            if (count($parts) < 2) {
                ++$skipped;
                continue;
            }
            try {
                $this->add($parts[0], $parts[1], (int) trim((string) ($parts[2] ?? '301')));
                ++$added;
            } catch (\DomainException) {
                ++$skipped;
            }
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        $this->db->update('mc_custom_redirect', ['enabled' => $enabled ? 1 : 0], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('mc_custom_redirect', ['id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public function all(string $search = ''): array
    {
        $where = '1=1';
        $params = [];
        if ($search !== '') {
            $where = '(source_path LIKE ? OR target_url LIKE ?)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $params = [$like, $like];
        }

        return $this->db->fetchAllAssociative("SELECT id,source_path,target_url,status_code,enabled,hit_count,last_hit_at,created_at FROM mc_custom_redirect WHERE $where ORDER BY id DESC LIMIT 500", $params);
    }

    public function recordNotFound(string $path, ?string $referer): void
    {
        $path = self::normalizePath($path);
        if ($path === '/' || mb_strlen($path) > 500) {
            return;
        }
        $this->db->executeStatement(
            'INSERT INTO mc_not_found_log (path_hash,path,hit_count,referer,first_seen_at,last_seen_at) VALUES (?,?,1,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE hit_count=hit_count+1,last_seen_at=UTC_TIMESTAMP(6),referer=COALESCE(VALUES(referer),referer)',
            [sha1($path), $path, $referer !== null && $referer !== '' ? mb_substr($referer, 0, 500) : null],
        );
        if (random_int(1, 200) === 1) {
            $this->trimNotFound();
        }
    }

    /** @return list<array<string,mixed>> */
    public function topNotFound(int $limit = 50): array
    {
        return $this->db->fetchAllAssociative('SELECT id,path,hit_count,referer,last_seen_at FROM mc_not_found_log ORDER BY hit_count DESC,last_seen_at DESC LIMIT ' . max(1, min(200, $limit)));
    }

    /**
     * The page of the shop that an unknown address most likely meant: the slug in the old address is compared with the slugs of products,
     * categories, brands and articles (the default language first). @return array{target:string,label:string,entity_type:string,score:int}|null
     */
    public function suggest(string $path): ?array
    {
        $last = rawurldecode((string) basename(rtrim((string) parse_url($path, PHP_URL_PATH), '/')));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) preg_replace('/\.(?:html?|php|aspx?)$/i', '', $last))), '-');
        if (mb_strlen($slug) < 3) {
            return null;
        }
        $likes = [];
        $params = [$slug];
        foreach (array_unique(array_filter(explode('-', $slug), static fn (string $t): bool => strlen($t) >= 4)) as $token) {
            $likes[] = 's.slug LIKE ?';
            $params[] = '%' . addcslashes($token, '%_\\') . '%';
            if (count($likes) >= 4) {
                break;
            }
        }
        $likes[] = "REPLACE(s.slug,'-','') LIKE ?";
        $params[] = '%' . str_replace('-', '', $slug) . '%';
        $where = 's.slug = ?' . ' OR ' . implode(' OR ', $likes);
        $rows = $this->db->fetchAllAssociative(
            "SELECT s.slug,s.path,s.entity_type,s.locale,COALESCE(sl.url_prefix,'') AS url_prefix,COALESCE(sl.is_default,0) AS is_default FROM mc_seo_route s LEFT JOIN mc_store_locale sl ON sl.store_id=s.store_id AND sl.locale_code=s.locale WHERE s.indexable=1 AND ($where) LIMIT 300",
            $params,
        );
        $best = null;
        $bestScore = 0;
        foreach ($rows as $r) {
            similar_text(str_replace('-', '', $slug), str_replace('-', '', (string) $r['slug']), $percent);
            $score = (int) round($percent) + ((int) $r['is_default'] === 1 ? 3 : 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $r;
            }
        }
        if ($best === null || $bestScore < 58) {
            return null;
        }
        $prefix = trim((string) $best['url_prefix'], '/');

        return ['target' => '/' . ($prefix !== '' ? $prefix . '/' : '') . ltrim((string) $best['path'], '/'), 'label' => (string) $best['slug'], 'entity_type' => (string) $best['entity_type'], 'score' => min(100, $bestScore)];
    }

    public function clearNotFound(): void
    {
        $this->db->executeStatement('DELETE FROM mc_not_found_log');
    }

    private function trimNotFound(): void
    {
        $over = (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_not_found_log') - self::MAX_NOT_FOUND_ROWS;
        if ($over > 0) {
            $this->db->executeStatement('DELETE FROM mc_not_found_log ORDER BY hit_count ASC,last_seen_at ASC LIMIT ' . $over);
        }
    }
}
