<?php

declare(strict_types=1);

namespace Commerce\Modules\Downloads\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Store-wide download centre: price lists, catalogues, certificates, manuals. Files are validated
 * by content (not by the client-supplied name), stored content-addressed under public/media/downloads
 * with an extension chosen from a fixed whitelist, and are never executable.
 */
final readonly class DownloadCenterService
{
    private const MAX_BYTES = 31_457_280;
    /** ext => accepted detected MIME types */
    private const TYPES = [
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    ];

    public function __construct(private Connection $db, private string $projectDir)
    {
    }

    public function upload(int $storeId, UploadedFile $file, string $title, string $description, string $group, int $sort): int
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.downloads.error_upload'));
        }
        $title = trim(strip_tags($title));
        if ($title === '' || mb_strlen($title) > 190) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.downloads.error_title'));
        }
        $size = (int) $file->getSize();
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.downloads.error_size'));
        }
        $ext = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $path = $file->getPathname();
        $detected = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($path));
        $head = (string) @file_get_contents($path, false, null, 0, 8);
        if (!isset(self::TYPES[$ext]) || !in_array($detected, self::TYPES[$ext], true) || !$this->magicMatches($ext, $head, $path)) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.downloads.error_type'));
        }
        $checksum = hash_file('sha256', $path);
        $key = 'downloads/' . gmdate('Y/m') . '/' . substr((string) $checksum, 0, 2) . '/' . $checksum . '.' . $ext;
        $target = rtrim($this->projectDir, '/\\') . '/public/media/' . $key;
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
            throw new \RuntimeException(CanonicalUiText::get('admin.downloads.error_upload'));
        }
        if (!is_file($target)) {
            $tmp = $target . '.part-' . bin2hex(random_bytes(8));
            if (!@copy($path, $tmp) || !@rename($tmp, $target)) {
                @unlink($tmp);
                throw new \RuntimeException(CanonicalUiText::get('admin.downloads.error_upload'));
            }
            @chmod($target, 0644);
        }
        $this->db->insert('mc_download_file', [
            'store_id' => $storeId, 'title' => $title, 'description' => mb_substr(trim(strip_tags($description)), 0, 500), 'file_url' => '/media/' . $key,
            'file_ext' => $ext, 'file_size' => $size, 'group_label' => mb_substr(trim(strip_tags($group)), 0, 120), 'status' => 'active',
            'sort_order' => max(0, min(65535, $sort)), 'download_count' => 0, 'created_at' => gmdate('Y-m-d H:i:s.u'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function adminList(int $storeId): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM mc_download_file WHERE store_id=? ORDER BY sort_order,id DESC', [$storeId]);
    }

    public function toggle(int $storeId, int $id): void
    {
        $this->db->executeStatement("UPDATE mc_download_file SET status=IF(status='active','hidden','active') WHERE id=? AND store_id=?", [$id, $storeId]);
    }

    public function delete(int $storeId, int $id): void
    {
        $this->db->delete('mc_download_file', ['id' => $id, 'store_id' => $storeId]);
    }

    /**
     * Public listing: store files plus visible product documents, grouped by label.
     *
     * @return list<array{group:string,items:list<array<string,mixed>>}>
     */
    public function publicGroups(int $storeId, string $locale, string $query): array
    {
        $needle = mb_strtolower(trim($query));
        $files = $this->db->fetchAllAssociative("SELECT id,title,description,file_ext ext,file_size size,group_label FROM mc_download_file WHERE store_id=? AND status='active' ORDER BY sort_order,id DESC", [$storeId]);
        $groups = [];
        foreach ($files as $f) {
            if ($needle !== '' && !str_contains(mb_strtolower($f['title'] . ' ' . $f['description']), $needle)) {
                continue;
            }
            $groups[(string) $f['group_label']][] = ['title' => $f['title'], 'description' => $f['description'], 'ext' => $f['ext'], 'size' => (int) $f['size'], 'url' => '/downloads/' . (int) $f['id'] . '/get', 'product' => null];
        }
        $docs = $this->db->fetchAllAssociative(
            "SELECT pd.title,pd.document_type,ma.storage_key,ma.bytes,pt.name product_name,sr.path product_path FROM mc_product_document pd
             JOIN mc_media_asset ma ON ma.id=pd.media_id
             JOIN mc_store_product sp ON sp.product_id=pd.product_id AND sp.store_id=?
             JOIN mc_product p ON p.id=pd.product_id AND p.status='published'
             LEFT JOIN mc_product_translation pt ON pt.product_id=pd.product_id AND pt.store_id=? AND pt.locale=?
             LEFT JOIN mc_seo_route sr ON sr.store_id=? AND sr.locale=? AND sr.entity_type='product' AND sr.entity_public_id=p.public_id
             WHERE pd.visible=1 AND (pd.locale=? OR pd.locale IS NULL) ORDER BY pt.name,pd.sort_order,pd.id LIMIT 300",
            [$storeId, $storeId, $locale, $storeId, $locale, $locale],
        );
        foreach ($docs as $d) {
            $haystack = mb_strtolower($d['title'] . ' ' . ($d['product_name'] ?? ''));
            if ($needle !== '' && !str_contains($haystack, $needle)) {
                continue;
            }
            $key = str_replace('\\', '/', (string) $d['storage_key']);
            if (str_contains($key, '..')) {
                continue;
            }
            $groups['@products'][] = ['title' => $d['title'], 'description' => '', 'ext' => strtolower((string) pathinfo($key, PATHINFO_EXTENSION)), 'size' => (int) $d['bytes'], 'url' => '/media/' . ltrim($key, '/'), 'product' => ['name' => (string) ($d['product_name'] ?? ''), 'path' => $d['product_path'] !== null ? '/' . ltrim((string) $d['product_path'], '/') : null]];
        }
        $out = [];
        foreach ($groups as $label => $items) {
            $out[] = ['group' => (string) $label, 'items' => $items];
        }
        usort($out, static fn (array $a, array $b): int => ($a['group'] === '@products') <=> ($b['group'] === '@products') ?: strcmp($a['group'], $b['group']));

        return $out;
    }

    /** Counts the download and returns the target URL, or null for an unknown or hidden file. */
    public function hit(int $storeId, int $id): ?string
    {
        $url = $this->db->fetchOne("SELECT file_url FROM mc_download_file WHERE id=? AND store_id=? AND status='active'", [$id, $storeId]);
        if (!is_string($url) || !str_starts_with($url, '/media/downloads/')) {
            return null;
        }
        $this->db->executeStatement('UPDATE mc_download_file SET download_count=download_count+1 WHERE id=?', [$id]);

        return $url;
    }

    private function magicMatches(string $ext, string $head, string $path): bool
    {
        return match ($ext) {
            'pdf' => str_starts_with($head, '%PDF-'),
            'zip', 'docx', 'xlsx' => str_starts_with($head, "PK\x03\x04"),
            'txt', 'csv' => !str_contains((string) @file_get_contents($path, false, null, 0, 65536), "\0"),
            default => false,
        };
    }
}
