<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Core\Id\PublicIdFactory;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Two file libraries next to the photo library: product documents (public PDF/TXT files) and digital downloads
 * (private files handed out after payment). Files are uploaded once, sorted into folders and then attached to any
 * number of products; a product keeps working with the file even if the library entry is moved.
 */
final readonly class FileLibraryService
{
    public const DOCUMENT = 'document';
    public const DIGITAL = 'digital';
    private const DOCUMENT_MAX = 20_971_520;
    private const DIGITAL_MAX = 262_144_000;
    private const DIGITAL_EXTENSIONS = ['pdf', 'txt', 'csv', 'zip', 'epub', 'mp3', 'mp4', 'webm', 'jpg', 'jpeg', 'png', 'webp'];
    private const DENIED_MIME = ['php', 'javascript', 'html', 'svg', 'x-httpd', 'x-sh', 'x-executable'];

    public function __construct(private Connection $db, private PublicIdFactory $publicIds, private string $projectDir)
    {
    }

    public static function valid(string $library): bool
    {
        return in_array($library, [self::DOCUMENT, self::DIGITAL], true);
    }

    /**
     * @return array{folder:?array{id:int,name:string},trail:list<array{id:int,name:string}>,folders:list<array{id:int,name:string,items:int}>,items:list<array<string,mixed>>}
     */
    public function browse(int $storeId, string $library, ?int $folderId, string $query = ''): array
    {
        $folder = $folderId !== null ? $this->folder($storeId, $library, $folderId) : null;
        $trail = [];
        for ($cursor = $folder, $guard = 0; $cursor !== null && $guard < 12; ++$guard) {
            array_unshift($trail, ['id' => $cursor['id'], 'name' => $cursor['name']]);
            $cursor = $cursor['parent_id'] !== null ? $this->folder($storeId, $library, $cursor['parent_id']) : null;
        }
        $folders = $this->db->fetchAllAssociative(
            'SELECT f.id,f.name,(SELECT COUNT(*) FROM mc_file_item i WHERE i.folder_id=f.id) items FROM mc_file_folder f
             WHERE f.store_id=? AND f.library=? AND ' . ($folder === null ? 'f.parent_id IS NULL' : 'f.parent_id=?') . ' ORDER BY f.name',
            $folder === null ? [$storeId, $library] : [$storeId, $library, $folder['id']],
        );
        $params = [$storeId, $library];
        $where = '';
        $query = trim($query);
        if ($query !== '') {
            $like = '%' . addcslashes(mb_substr($query, 0, 80, 'UTF-8'), '%_\\') . '%';
            $where = ' AND (title LIKE ? OR original_filename LIKE ?)';
            array_push($params, $like, $like);
        } elseif ($folder === null) {
            $where = ' AND folder_id IS NULL';
        } else {
            $where = ' AND folder_id=?';
            $params[] = $folder['id'];
        }
        $items = $this->db->fetchAllAssociative(
            "SELECT public_id,title,original_filename,mime_type,bytes,created_at FROM mc_file_item WHERE store_id=? AND library=?{$where} ORDER BY id DESC LIMIT 200",
            $params,
        );

        return [
            'folder' => $folder === null ? null : ['id' => $folder['id'], 'name' => $folder['name']],
            'trail' => $trail,
            'folders' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'items' => (int) $r['items']], $folders),
            'items' => array_map(static fn (array $r): array => [
                'id' => Uuid::fromBinary((string) $r['public_id'])->toRfc4122(),
                'title' => (string) $r['title'],
                'filename' => (string) $r['original_filename'],
                'mime' => (string) $r['mime_type'],
                'bytes' => (int) $r['bytes'],
                'created_at' => (string) $r['created_at'],
            ], $items),
        ];
    }

    public function createFolder(int $storeId, string $library, ?int $parentId, string $name): int
    {
        $name = trim(mb_substr(strip_tags($name), 0, 190, 'UTF-8'));
        if ($name === '') {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.files.error.folder_name'));
        }
        if ($parentId !== null && $this->folder($storeId, $library, $parentId) === null) {
            throw new \DomainException(CanonicalUiText::get('admin.files.error.folder_missing'));
        }
        $this->db->insert('mc_file_folder', ['store_id' => $storeId, 'library' => $library, 'parent_id' => $parentId, 'name' => $name, 'created_at' => $this->now()]);

        return (int) $this->db->lastInsertId();
    }

    /** @return array<string,mixed> the stored library entry */
    public function upload(int $storeId, string $library, UploadedFile $file, ?int $folderId): array
    {
        if (!self::valid($library)) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.files.error.library'));
        }
        if ($folderId !== null && $this->folder($storeId, $library, $folderId) === null) {
            throw new \DomainException(CanonicalUiText::get('admin.files.error.folder_missing'));
        }
        if (!$file->isValid()) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.files.error.upload'));
        }
        $size = (int) $file->getSize();
        $max = $library === self::DOCUMENT ? self::DOCUMENT_MAX : self::DIGITAL_MAX;
        if ($size < 1 || $size > $max) {
            throw new \InvalidArgumentException(CanonicalUiText::get($library === self::DOCUMENT ? 'admin.files.error.size_document' : 'admin.files.error.size_digital'));
        }
        $path = $file->getPathname();
        $original = mb_substr(trim(strip_tags((string) $file->getClientOriginalName())), 0, 255, 'UTF-8');
        $extension = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
        $mime = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($path));

        if ($library === self::DOCUMENT) {
            $head = (string) @file_get_contents($path, false, null, 0, 8);
            if ($mime === 'application/pdf' && str_starts_with($head, '%PDF-')) {
                $extension = 'pdf';
            } elseif (in_array($mime, ['text/plain', 'text/x-readme'], true) && !str_contains((string) @file_get_contents($path), "\0")) {
                $extension = 'txt';
                $mime = 'text/plain';
            } else {
                throw new \InvalidArgumentException(CanonicalUiText::get('admin.files.error.type_document'));
            }
        } else {
            if (!in_array($extension, self::DIGITAL_EXTENSIONS, true)) {
                throw new \InvalidArgumentException(CanonicalUiText::get('admin.files.error.type_digital'));
            }
            foreach (self::DENIED_MIME as $fragment) {
                if (str_contains($mime, $fragment)) {
                    throw new \InvalidArgumentException(CanonicalUiText::get('admin.files.error.type_digital'));
                }
            }
        }

        $checksum = (string) hash_file('sha256', $path);
        if (strlen($checksum) !== 64) {
            throw new \RuntimeException(CanonicalUiText::get('admin.files.error.upload'));
        }
        $key = ($library === self::DOCUMENT ? 'documents/' : 'digital/') . gmdate('Y/m') . '/' . substr($checksum, 0, 2) . '/' . $checksum . '.' . $extension;
        $target = $this->absolute($library, $key);
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), $library === self::DOCUMENT ? 0755 : 0750, true) && !is_dir(dirname($target))) {
            throw new \RuntimeException(CanonicalUiText::get('admin.files.error.upload'));
        }
        if (!is_file($target)) {
            $tmp = $target . '.part-' . bin2hex(random_bytes(8));
            if (!@copy($path, $tmp) || !@rename($tmp, $target)) {
                @unlink($tmp);
                throw new \RuntimeException(CanonicalUiText::get('admin.files.error.upload'));
            }
            @chmod($target, $library === self::DOCUMENT ? 0644 : 0640);
        }
        $title = trim((string) preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $original));
        $uuid = $this->publicIds->generate();
        $this->db->insert('mc_file_item', [
            'public_id' => $uuid->toBinary(), 'store_id' => $storeId, 'library' => $library, 'folder_id' => $folderId,
            'title' => $title !== '' ? mb_substr($title, 0, 255, 'UTF-8') : 'file', 'original_filename' => $original !== '' ? $original : 'file.' . $extension,
            'storage_key' => $key, 'mime_type' => mb_substr($mime !== '' ? $mime : 'application/octet-stream', 0, 190), 'bytes' => $size, 'created_at' => $this->now(),
        ]);

        return $this->item($storeId, $library, $uuid->toRfc4122()) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function item(int $storeId, string $library, string $publicId): ?array
    {
        try {
            $binary = Uuid::fromString($publicId)->toBinary();
        } catch (\Throwable) {
            return null;
        }
        $row = $this->db->fetchAssociative('SELECT * FROM mc_file_item WHERE store_id=? AND library=? AND public_id=?', [$storeId, $library, $binary]);

        return is_array($row) ? $row : null;
    }

    /** Puts a library document on a product (the file is already stored and checked). */
    public function attachDocument(int $storeId, int $productId, string $itemPublicId, string $title, ?string $locale, string $documentType): void
    {
        $item = $this->item($storeId, self::DOCUMENT, $itemPublicId);
        if ($item === null) {
            throw new \DomainException(CanonicalUiText::get('admin.files.error.item_missing'));
        }
        $title = trim(strip_tags($title)) !== '' ? mb_substr(trim(strip_tags($title)), 0, 255, 'UTF-8') : (string) $item['title'];
        $this->db->transactional(function (Connection $db) use ($item, $productId, $title, $locale, $documentType): void {
            $key = (string) $item['storage_key'];
            $asset = $db->fetchAssociative('SELECT id FROM mc_media_asset WHERE storage_key_hash=? LIMIT 1', [hash('sha256', $key, true)]);
            if (!is_array($asset)) {
                $checksum = (string) pathinfo($key, PATHINFO_FILENAME);
                $db->insert('mc_media_asset', [
                    'public_id' => $this->publicIds->generate()->toBinary(), 'storage_key' => $key, 'storage_key_hash' => hash('sha256', $key, true),
                    'mime_type' => (string) $item['mime_type'], 'bytes' => (int) $item['bytes'], 'width' => null, 'height' => null,
                    'checksum_sha256' => strlen($checksum) === 64 && ctype_xdigit($checksum) ? hex2bin($checksum) : random_bytes(32),
                    'metadata' => json_encode(['kind' => 'product_document', 'safe_extension' => pathinfo($key, PATHINFO_EXTENSION)], JSON_THROW_ON_ERROR),
                    'created_at' => $this->now(),
                ]);
                $assetId = (int) $db->lastInsertId();
            } else {
                $assetId = (int) $asset['id'];
            }
            $now = $this->now();
            $db->insert('mc_product_document', [
                'public_id' => $this->publicIds->generate()->toBinary(), 'product_id' => $productId, 'media_id' => $assetId, 'locale' => $locale,
                'document_type' => $documentType, 'title' => $title, 'document_version' => null, 'sort_order' => 100, 'visible' => 1,
                'valid_from' => null, 'valid_to' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        });
    }

    /** Hands a library file out with a digital product. */
    public function attachDigital(int $storeId, int $productId, string $itemPublicId, string $title, int $maxDownloads, ?int $accessDays): void
    {
        $item = $this->item($storeId, self::DIGITAL, $itemPublicId);
        if ($item === null) {
            throw new \DomainException(CanonicalUiText::get('admin.files.error.item_missing'));
        }
        if ((string) $this->db->fetchOne('SELECT product_type FROM mc_product WHERE id=? LIMIT 1', [$productId]) !== 'digital') {
            throw new \DomainException(CanonicalUiText::get('admin.files.error.not_digital'));
        }
        $title = trim(strip_tags($title)) !== '' ? mb_substr(trim(strip_tags($title)), 0, 255, 'UTF-8') : (string) $item['title'];
        $checksum = (string) pathinfo((string) $item['storage_key'], PATHINFO_FILENAME);
        $now = $this->now();
        $this->db->insert('mc_product_digital_asset', [
            'public_id' => $this->publicIds->generate()->toBinary(), 'product_id' => $productId, 'title' => $title,
            'original_filename' => (string) $item['original_filename'], 'storage_key' => (string) $item['storage_key'], 'mime_type' => (string) $item['mime_type'],
            'bytes' => (int) $item['bytes'], 'checksum_sha256' => strlen($checksum) === 64 && ctype_xdigit($checksum) ? hex2bin($checksum) : random_bytes(32),
            'max_downloads' => max(1, min(1000, $maxDownloads)), 'access_days' => $accessDays === null ? null : max(1, min(3650, $accessDays)),
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** @return array{id:int,name:string,parent_id:?int}|null */
    private function folder(int $storeId, string $library, int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT id,name,parent_id FROM mc_file_folder WHERE id=? AND store_id=? AND library=?', [$id, $storeId, $library]);

        return is_array($row) ? ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id']] : null;
    }

    private function absolute(string $library, string $key): string
    {
        if (str_contains($key, '..') || str_starts_with($key, '/')) {
            throw new \RuntimeException(CanonicalUiText::get('admin.files.error.upload'));
        }

        return rtrim($this->projectDir, '/\\') . ($library === self::DOCUMENT ? '/public/media/' : '/var/storage/') . $key;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
