<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

use Commerce\Core\Id\PublicIdFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use GdImage;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class MediaImageService
{
    private const MAX_UPLOAD_BYTES = 20971520;
    private const MAX_DIMENSION = 12000;
    private const MAX_PIXELS = 48000000;

    public function __construct(
        private Connection $connection,
        private PublicIdFactory $publicIds,
        private MediaVariantService $variants,
        private string $projectDir,
    ) {
    }

    /**
     * Stores one uploaded picture as an ORIGINAL in a library folder (public/media/<folder>/<name>.<ext>): upright, without
     * camera metadata, at most ORIGINAL_MAX px on the long side. Sizes and formats are made later as cache files.
     * The file keeps a readable name; a name that is taken gets -2, -3 and so on. The same picture uploaded again returns the
     * existing one.
     */
    public function upload(UploadedFile $file, ?int $storeId = null, ?int $folderId = null, ?string $directory = null): ImageUploadResult
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.mediaimageservice.zavantazhennia_zobrazhennia_ne_zavershylosia_uspishn'));
        }
        $bytes = $file->getSize();
        if (!is_int($bytes) || $bytes < 1 || $bytes > self::MAX_UPLOAD_BYTES) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.mediaimageservice.zobrazhennia_maie_buty_ne_bilshe_20_mb'));
        }
        $path = $file->getPathname();
        $heic = new HeicDecoder();
        $fromHeic = false;
        $heicJpeg = null;
        if ($heic->isHeicFile($path)) {
            try {
                $heicJpeg = $heic->toJpeg($path);
            } catch (RuntimeException $exception) {
                throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get($exception->getMessage() === 'heic_decoder_missing' ? 'media.heic_decoder_missing' : 'media.heic_decode_failed'));
            }
            $fromHeic = true;
            $info = @getimagesizefromstring($heicJpeg);
        } else {
            $info = @getimagesize($path);
        }
        if (!is_array($info) || !isset($info[0], $info[1], $info['mime'])) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.mediaimageservice.fail_ne_ie_pidtrymuvanym_rastrovym_zobrazhenniam'));
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        $mime = strtolower((string) $info['mime']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.mediaimageservice.dozvoleni_jpeg_png_webp_ta_avif'));
        }
        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || ($width * $height) > self::MAX_PIXELS) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.mediaimageservice.rozdilna_zdatnist_zobrazhennia_perevyshchuie_bezpech'));
        }
        $raw = $heicJpeg ?? @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.mediaimageservice.ne_vdalosia_prochytaty_zavantazhene_zobrazhennia'));
        }
        $isJpeg = $fromHeic || $mime === 'image/jpeg';
        $extension = $isJpeg ? 'jpg' : match ($mime) { 'image/png' => 'png', 'image/webp' => 'webp', default => 'avif' };

        $data = $raw;
        $storedWidth = $width;
        $storedHeight = $height;
        $long = max($width, $height);
        // A JPEG with camera metadata (GPS, device, rotation) is always re-encoded; anything too large is scaled down.
        $hasMetadata = $isJpeg && str_contains(substr($raw, 0, 131072), "Exif\0\0");
        if ($hasMetadata || $long > MediaImageProfile::ORIGINAL_MAX) {
            $source = @imagecreatefromstring($raw);
            if (!$source instanceof GdImage) {
                throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.mediaimageservice.gd_ne_zmih_dekoduvaty_zobrazhennia'));
            }
            try {
                if ($isJpeg && !$fromHeic) {
                    $source = $this->orientedUpright($source, $raw, $width, $height);
                }
                [$data, $storedWidth, $storedHeight] = $this->encodeOriginal($source, $width, $height, $extension);
            } finally {
                imagedestroy($source);
            }
        }

        $checksum = hash('sha256', $data, true);
        $existing = $this->connection->fetchAssociative('SELECT id,public_id,storage_key,width,height FROM mc_media_asset WHERE checksum_sha256=? AND mime_type=? LIMIT 1', [$checksum, 'image/' . ($extension === 'jpg' ? 'jpeg' : $extension)]);
        if (is_array($existing) && is_file($this->publicMediaPath((string) $existing['storage_key']))) {
            return new ImageUploadResult(
                (int) $existing['id'],
                \Symfony\Component\Uid\Uuid::fromBinary((string) $existing['public_id'])->toRfc4122(),
                '/media/' . ltrim((string) $existing['storage_key'], '/'),
                (string) $existing['storage_key'],
                (int) $existing['width'],
                (int) $existing['height'],
                [],
            );
        }

        $folderDirectory = $directory !== null && $directory !== '' ? MediaSlug::make($directory, 'uploads') : $this->folderDirectory($storeId, $folderId);
        $base = MediaSlug::make(pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME), 'image');
        [$key, $target] = $this->claimFile($folderDirectory, $base, $extension, $data);
        $mimeType = 'image/' . ($extension === 'jpg' ? 'jpeg' : $extension);
        $metadata = [
            'source_mime' => $fromHeic ? 'image/heic' : $mime,
            'source_name' => mb_substr(basename((string) $file->getClientOriginalName()), 0, 190, 'UTF-8'),
            'focal_point' => ['x' => 0.5, 'y' => 0.5],
            'generator' => 'gd',
        ];
        $uuid = $this->publicIds->generate();
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        try {
            $this->connection->insert('mc_media_asset', [
                'public_id' => $uuid->toBinary(),
                'storage_key' => $key,
                'storage_key_hash' => hash('sha256', $key, true),
                'mime_type' => $mimeType,
                'bytes' => strlen($data),
                'width' => $storedWidth,
                'height' => $storedHeight,
                'checksum_sha256' => $checksum,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);
        } catch (\Throwable $exception) {
            @unlink($target); // no record, no file
            throw $exception;
        }
        $assetId = (int) $this->connection->lastInsertId();
        if ($storeId !== null) {
            $this->connection->executeStatement(
                "INSERT IGNORE INTO mc_store_media_asset (store_id,asset_id,folder_id,tags_json,created_at,updated_at) VALUES (?,?,?,'[]',?,?)",
                [$storeId, $assetId, $this->ownedFolder($storeId, $folderId), $now, $now],
            );
        }

        return new ImageUploadResult($assetId, $uuid->toRfc4122(), '/media/' . $key, $key, $storedWidth, $storedHeight, [
            ['format' => $extension, 'width' => $storedWidth, 'height' => $storedHeight, 'key' => $key, 'mime' => $mimeType, 'bytes' => strlen($data), 'role' => 'original'],
        ]);
    }

    /** Folder id of the store, or null when it does not exist / belongs to another store. */
    private function ownedFolder(int $storeId, ?int $folderId): ?int
    {
        if ($folderId === null || $folderId < 1) {
            return null;
        }

        return (bool) $this->connection->fetchOne('SELECT 1 FROM mc_media_folder WHERE id=? AND store_id=?', [$folderId, $storeId]) ? $folderId : null;
    }

    /** Directory of a library folder under public/media ("uploads" for pictures without a folder). */
    private function folderDirectory(?int $storeId, ?int $folderId): string
    {
        $folderId = $storeId !== null ? $this->ownedFolder($storeId, $folderId) : null;
        if ($folderId === null) {
            return 'uploads';
        }
        $parts = [];
        $current = $folderId;
        for ($depth = 0; $current !== null && $depth < 8; ++$depth) {
            $row = $this->connection->fetchAssociative('SELECT parent_id,slug FROM mc_media_folder WHERE id=?', [$current]);
            if (!is_array($row)) {
                break;
            }
            array_unshift($parts, MediaSlug::make((string) $row['slug'], 'folder-' . $current, 60));
            $current = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        }
        if ($parts === [] || $parts[0] === 'cache') {
            array_unshift($parts, 'library'); // "cache" is the reserved name of the size cache
        }

        return implode('/', $parts);
    }

    /**
     * Creates the file under a free name (the name of the upload, then -2, -3 …) and returns its key and path. The file is
     * created exclusively, so two uploads of the same name at the same moment cannot overwrite each other.
     *
     * @return array{0:string,1:string}
     */
    private function claimFile(string $directory, string $base, string $extension, string $data): array
    {
        $this->ensureDirectory($this->publicMediaPath($directory));
        for ($n = 1; $n < 10000; ++$n) {
            $name = $n === 1 ? $base : $base . '-' . $n;
            $stem = $directory . '/' . $name;
            $taken = false;
            foreach (['jpg', 'jpeg', 'png', 'webp', 'avif'] as $other) {
                if ($other !== $extension && is_file($this->publicMediaPath($stem . '.' . $other))) {
                    $taken = true; // the same stem with another extension would share its cache files
                }
            }
            if ($taken) {
                continue;
            }
            $key = $stem . '.' . $extension;
            $target = $this->publicMediaPath($key);
            $handle = @fopen($target, 'xb');
            if ($handle === false) {
                continue;
            }
            $written = fwrite($handle, $data);
            fclose($handle);
            if ($written !== strlen($data)) {
                @unlink($target);
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.93414445f679'));
            }
            @chmod($target, 0644);

            return [$key, $target];
        }
        throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.93414445f679'));
    }

    /**
     * Re-encodes an image as a clean original: scaled down to ORIGINAL_MAX on the long side, no metadata.
     *
     * @return array{0:string,1:int,2:int} bytes, width, height
     */
    private function encodeOriginal(GdImage $source, int $width, int $height, string $extension): array
    {
        $scale = min(1.0, MediaImageProfile::ORIGINAL_MAX / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$canvas instanceof GdImage) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9fa25998ff4b'));
        }
        try {
            if ($extension === 'jpg') {
                imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, (int) imagecolorallocate($canvas, 255, 255, 255));
            } else {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, (int) imagecolorallocatealpha($canvas, 255, 255, 255, 127));
            }
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
            ob_start();
            $ok = match ($extension) {
                'png' => imagepng($canvas, null, 6),
                'webp' => function_exists('imagewebp') && imagewebp($canvas, null, 90),
                'avif' => function_exists('imageavif') && imageavif($canvas, null, 80),
                default => imagejpeg($canvas, null, 92),
            };
            $encoded = (string) ob_get_clean();
        } finally {
            imagedestroy($canvas);
        }
        if (!$ok || $encoded === '') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.93414445f679'));
        }

        return [$encoded, $targetWidth, $targetHeight];
    }

    public function attachToProduct(int $productId, int $assetId, string $role = 'gallery', int $sortOrder = 0, ?string $altText = null, ?int $variantId = null): void
    {
        if (!in_array($role, ['primary','gallery'], true)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d9cb66574d00'));
        }
        $this->connection->transactional(function (Connection $db) use ($productId, $assetId, $role, $sortOrder, $altText, $variantId): void {
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_product WHERE id=?', [$productId]) !== 1) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7dd50cc69f60'));
            }
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_media_asset WHERE id=?', [$assetId]) !== 1) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9a697f5165d4'));
            }
            if ($variantId !== null && (int) $db->fetchOne('SELECT COUNT(*) FROM mc_product_variant WHERE id=? AND product_id=?', [$variantId, $productId]) !== 1) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7b2ed08f2271'));
            }
            if ($role === 'primary') {
                $db->executeStatement("UPDATE mc_product_media SET role='gallery' WHERE product_id=? AND role='primary'", [$productId]);
            }
            $db->executeStatement(
                "INSERT INTO mc_product_media (product_id,variant_id,media_asset_id,role,sort_order,alt_text) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE variant_id=VALUES(variant_id),sort_order=VALUES(sort_order),alt_text=VALUES(alt_text)",
                [$productId, $variantId, $assetId, $role, $sortOrder, $this->cleanAlt($altText)],
            );
            if ((int) $db->fetchOne("SELECT COUNT(*) FROM mc_product_media WHERE product_id=? AND role='primary'", [$productId]) === 0) {
                $db->executeStatement("UPDATE mc_product_media SET role='primary' WHERE product_id=? AND media_asset_id=? AND role='gallery'", [$productId, $assetId]);
            }
        });
        $this->warmPrimary($productId);
    }

    public function warmPrimaryOf(int $productId): void
    {
        $this->warmPrimary($productId);
    }

    /** The main photo of a product gets its everyday sizes right away; a failure here never blocks saving the product. */
    private function warmPrimary(int $productId): void
    {
        try {
            $key = $this->connection->fetchOne("SELECT ma.storage_key FROM mc_product_media pm JOIN mc_media_asset ma ON ma.id=pm.media_asset_id WHERE pm.product_id=? AND pm.role='primary' LIMIT 1", [$productId]);
            if (is_string($key) && $key !== '') {
                $this->variants->warm($key);
            }
        } catch (\Throwable) {
        }
    }

    public function detachFromProduct(int $productId, int $assetId): void
    {
        $this->connection->transactional(function (Connection $db) use ($productId, $assetId): void {
            $wasPrimary = (int) $db->fetchOne("SELECT COUNT(*) FROM mc_product_media WHERE product_id=? AND media_asset_id=? AND role='primary'", [$productId, $assetId]) > 0;
            $db->executeStatement('DELETE FROM mc_product_media WHERE product_id=? AND media_asset_id=?', [$productId, $assetId]);
            if ($wasPrimary) {
                $next = $db->fetchOne("SELECT media_asset_id FROM mc_product_media WHERE product_id=? AND role='gallery' ORDER BY sort_order,media_asset_id LIMIT 1", [$productId]);
                if ($next !== false) {
                    $db->executeStatement("UPDATE mc_product_media SET role='primary' WHERE product_id=? AND media_asset_id=? AND role='gallery'", [$productId, (int) $next]);
                }
            }
        });
        $this->warmPrimary($productId);
    }

    public function setPrimary(int $productId, int $assetId): void
    {
        $this->connection->transactional(function (Connection $db) use ($productId, $assetId): void {
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_product_media WHERE product_id=? AND media_asset_id=?', [$productId, $assetId]) !== 1) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.170b90665a9d'));
            }
            $db->executeStatement("UPDATE mc_product_media SET role='gallery' WHERE product_id=? AND role='primary'", [$productId]);
            $db->executeStatement("UPDATE mc_product_media SET role='primary' WHERE product_id=? AND media_asset_id=?", [$productId, $assetId]);
        });
        $this->warmPrimary($productId);
    }

    public function updateProductImage(
        int $productId,
        int $assetId,
        ?string $altText,
        float $focalX = 0.5,
        float $focalY = 0.5,
    ): void {
        $focalX = max(0.0, min(1.0, $focalX));
        $focalY = max(0.0, min(1.0, $focalY));

        $this->connection->transactional(function (Connection $db) use ($productId, $assetId, $altText, $focalX, $focalY): void {
            $attached = $db->fetchOne('SELECT COUNT(*) FROM mc_product_media WHERE product_id=? AND media_asset_id=?', [$productId, $assetId]);
            if ((int) $attached !== 1) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.170b90665a9d'));
            }
            $row = $db->fetchAssociative('SELECT metadata FROM mc_media_asset WHERE id=? FOR UPDATE', [$assetId]);
            if (!is_array($row)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9a697f5165d4'));
            }
            $metadata = $this->decodeMetadata($row['metadata'] ?? null);
            $metadata['focal_point'] = ['x' => round($focalX, 4), 'y' => round($focalY, 4)];
            $db->update('mc_media_asset', [
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ], ['id' => $assetId]);
            $db->update('mc_product_media', [
                'alt_text' => $this->cleanAlt($altText),
            ], ['product_id' => $productId, 'media_asset_id' => $assetId]);
        });
    }

    /** @return list<array<string,mixed>> */
    public function productImages(int $productId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT ma.id,ma.public_id,ma.storage_key,ma.width,ma.height,ma.metadata,pm.role,pm.sort_order,pm.alt_text FROM mc_product_media pm JOIN mc_media_asset ma ON ma.id=pm.media_asset_id WHERE pm.product_id=? AND pm.role IN ('primary','gallery') ORDER BY (pm.role='primary') DESC,pm.sort_order,ma.id",
            [$productId],
        );
        return array_map(function (array $row): array {
            $metadata = $this->decodeMetadata($row['metadata'] ?? null);
            return [
                'id' => (int) $row['id'],
                'public_id' => \Symfony\Component\Uid\Uuid::fromBinary((string) $row['public_id'])->toRfc4122(),
                'url' => '/media/' . ltrim((string) $row['storage_key'], '/'),
                'width' => (int) $row['width'], 'height' => (int) $row['height'],
                'role' => (string) $row['role'], 'sort_order' => (int) $row['sort_order'],
                'alt_text' => (string) ($row['alt_text'] ?? ''),
                'focal_point' => is_array($metadata['focal_point'] ?? null) ? $metadata['focal_point'] : ['x'=>0.5,'y'=>0.5],
            ];
        }, $rows);
    }

    /** Applies the EXIF orientation of a phone photo, so the master is upright and the variants need no rotation. */
    private function orientedUpright(GdImage $image, string $raw, int &$width, int &$height): GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode(substr($raw, 0, 65536)));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $angle = match ($orientation) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if (!$rotated instanceof GdImage) {
            return $image;
        }
        imagedestroy($image);
        if ($angle !== 180) {
            [$width, $height] = [$height, $width];
        }

        return $rotated;
    }

    /** @return array<string,mixed> */
    private function decodeMetadata(mixed $json): array
    {
        if (!is_string($json) || trim($json) === '') return [];
        try { $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR); } catch (\JsonException) { return []; }
        return is_array($value) ? $value : [];
    }

    private function publicMediaPath(string $key): string
    {
        $key = str_replace('\\', '/', trim($key));
        if ($key === '' || str_contains($key, '..') || str_starts_with($key, '/')) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.604f39a5d4ec'));
        }
        return rtrim($this->projectDir, '/\\') . '/public/media/' . $key;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.8b75285e1cfe'));
        }
    }

    private function cleanAlt(?string $alt): ?string
    {
        if ($alt === null) return null;
        $alt = trim(strip_tags($alt));
        return $alt === '' ? null : mb_substr($alt, 0, 500, 'UTF-8');
    }
}
