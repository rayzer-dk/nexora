<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Core\Configuration\ConfigurationRevisionStore;
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
        private ConfigurationRevisionStore $revisions,
        private MediaVariantService $variants,
        private string $projectDir,
    ) {
    }

    public function upload(UploadedFile $file, ?int $storeId = null): ImageUploadResult
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
        if (!in_array($mime, ['image/jpeg','image/png','image/webp','image/avif'], true)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.mediaimageservice.dozvoleni_jpeg_png_webp_ta_avif'));
        }
        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || ($width * $height) > self::MAX_PIXELS) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.mediaimageservice.rozdilna_zdatnist_zobrazhennia_perevyshchuie_bezpech'));
        }
        $raw = $heicJpeg ?? @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.mediaimageservice.ne_vdalosia_prochytaty_zavantazhene_zobrazhennia'));
        }
        $source = @imagecreatefromstring($raw);
        if (!$source instanceof GdImage) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.mediaimageservice.gd_ne_zmih_dekoduvaty_zobrazhennia'));
        }

        try {
            if (!$fromHeic && $mime === 'image/jpeg') {
                $source = $this->orientedUpright($source, $raw, $width, $height);
            }
            $checksum = hash('sha256', $raw);
            $relativeBase = 'catalog/' . substr($checksum, 0, 2) . '/' . $checksum;
            $profile = $storeId !== null ? $this->processingProfile($storeId) : MediaImageProfile::RECOMMENDED;
            if ($fromHeic && in_array($profile['format'], ['original', 'jpeg', 'png'], true)) {
                $profile['format'] = 'webp'; // HEIC is not browser-friendly: it is always converted to WebP (or AVIF when the store chose it)
            }
            if (in_array($profile['format'], ['webp', 'avif'], true) && !function_exists($profile['format'] === 'avif' ? 'imageavif' : 'imagewebp')) {
                $profile['format'] = 'original'; // this PHP build cannot write the chosen format: keep the picture's own format instead of failing the upload
            }
            $derivatives = $this->generateDerivatives($source, $width, $height, $relativeBase, $mime, $profile);
            if ($profile['keep_source']) {
                $derivatives[] = $this->keepSource($raw, $relativeBase, $fromHeic ? 'image/jpeg' : $mime, $width, $height);
            }
            if ($derivatives === []) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.mediaimageservice.ne_vdalosia_stvoryty_optymizovani_kopii_zobrazhennia'));
            }
            $preferred = $this->preferredDerivative($derivatives);
            $existing = $this->connection->fetchAssociative('SELECT id,public_id,storage_key,width,height,metadata FROM mc_media_asset WHERE storage_key_hash=? LIMIT 1', [hash('sha256', $preferred['key'], true)]);
            if (is_array($existing)) {
                $existingMetadata = $this->decodeMetadata($existing['metadata'] ?? null);
                return new ImageUploadResult(
                    (int) $existing['id'],
                    \Symfony\Component\Uid\Uuid::fromBinary((string) $existing['public_id'])->toRfc4122(),
                    '/media/' . ltrim((string) $existing['storage_key'], '/'),
                    (string) $existing['storage_key'],
                    (int) $existing['width'],
                    (int) $existing['height'],
                    is_array($existingMetadata['derivatives'] ?? null) ? $existingMetadata['derivatives'] : $derivatives,
                );
            }
            $metadata = [
                'source_mime' => $fromHeic ? 'image/heic' : $mime,
                'source_checksum' => $checksum,
                'focal_point' => ['x' => 0.5, 'y' => 0.5],
                'derivatives' => $derivatives,
                'generator' => 'gd',
            ];
            $uuid = $this->publicIds->generate();
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            $this->connection->insert('mc_media_asset', [
                'public_id' => $uuid->toBinary(),
                'storage_key' => $preferred['key'],
                'storage_key_hash' => hash('sha256', $preferred['key'], true),
                'mime_type' => $preferred['mime'],
                'bytes' => $preferred['bytes'],
                'width' => $preferred['width'],
                'height' => $preferred['height'],
                'checksum_sha256' => hash('sha256', $raw, true),
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);
            $assetId = (int) $this->connection->lastInsertId();
            if ($storeId !== null) {
                $this->connection->executeStatement(
                    "INSERT IGNORE INTO mc_store_media_asset (store_id,asset_id,folder_id,tags_json,created_at,updated_at) VALUES (?,?,NULL,'[]',?,?)",
                    [$storeId, $assetId, $now, $now],
                );
            }
            return new ImageUploadResult(
                $assetId,
                $uuid->toRfc4122(),
                '/media/' . $preferred['key'],
                $preferred['key'],
                $preferred['width'],
                $preferred['height'],
                $derivatives,
            );
        } finally {
            imagedestroy($source);
        }
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
        int $sortOrder,
        ?string $altText,
        float $focalX = 0.5,
        float $focalY = 0.5,
    ): void {
        $sortOrder = max(0, min(100000, $sortOrder));
        $focalX = max(0.0, min(1.0, $focalX));
        $focalY = max(0.0, min(1.0, $focalY));

        $this->connection->transactional(function (Connection $db) use ($productId, $assetId, $sortOrder, $altText, $focalX, $focalY): void {
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
                'sort_order' => $sortOrder,
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
                'derivatives' => is_array($metadata['derivatives'] ?? null) ? $metadata['derivatives'] : [],
                'focal_point' => is_array($metadata['focal_point'] ?? null) ? $metadata['focal_point'] : ['x'=>0.5,'y'=>0.5],
            ];
        }, $rows);
    }

    /**
     * Writes the one MASTER file of an upload (at most MASTER_WIDTH px wide). Every other size is made from it later:
     * the main product photo right away, everything else on the first request (see MediaVariantService).
     *
     * @param array{format:string,quality:int,keep_source:bool,presets:array<string,int>,generation:int} $profile
     * @return list<array{format:string,width:int,height:int,key:string,mime:string,bytes:int,role?:string}>
     */
    private function generateDerivatives(GdImage $source, int $sourceWidth, int $sourceHeight, string $relativeBase, string $sourceMime, array $profile): array
    {
        $format = $profile['format'] === 'original' ? $this->formatFromMime($sourceMime) : $profile['format'];
        $width = min($sourceWidth, MediaImageProfile::MASTER_WIDTH);

        return [$this->resizeAndWrite($source, $sourceWidth, $sourceHeight, $width, $relativeBase, $format, $profile['quality'])];
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

    /** @return array{format:string,width:int,height:int,key:string,mime:string,bytes:int} */
    private function resizeAndWrite(GdImage $source,int $sourceWidth,int $sourceHeight,int $width,string $base,string $format,int $quality): array
    {
        $height=max(1,(int)round($sourceHeight*($width/$sourceWidth)));
        $canvas=imagecreatetruecolor($width,$height);
        if(!$canvas instanceof GdImage) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9fa25998ff4b'));
        if($format==='jpeg'){
            // JPEG has no alpha channel: flatten transparency onto white instead of black.
            imagefilledrectangle($canvas,0,0,$width,$height,(int)imagecolorallocate($canvas,255,255,255));
        }else{
            imagealphablending($canvas,false); imagesavealpha($canvas,true);
            imagefilledrectangle($canvas,0,0,$width,$height,(int)imagecolorallocatealpha($canvas,255,255,255,127));
        }
        imagecopyresampled($canvas,$source,0,0,0,0,$width,$height,$sourceWidth,$sourceHeight);
        try{return $this->writeByFormat($canvas,$base,$format,$quality,$width,$height);}finally{imagedestroy($canvas);}
    }

    /** Stores the untouched upload (HEIC: its JPEG conversion) next to the derivatives. It is never used on the storefront. @return array{format:string,width:int,height:int,key:string,mime:string,bytes:int,role:string} */
    private function keepSource(string $raw,string $relativeBase,string $mime,int $width,int $height): array
    {
        $extension=match($mime){'image/png'=>'png','image/webp'=>'webp','image/avif'=>'avif',default=>'jpg'};
        $key=$relativeBase.'.source.'.$extension;
        $path=$this->publicMediaPath($key); $this->ensureDirectory(dirname($path));
        if(@file_put_contents($path,$raw)===false) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.93414445f679'));
        @chmod($path,0644);
        return ['format'=>$this->formatFromMime($mime),'width'=>$width,'height'=>$height,'key'=>$key,'mime'=>$mime,'bytes'=>strlen($raw),'role'=>'source'];
    }

    /** @return array{format:string,width:int,height:int,key:string,mime:string,bytes:int} */
    private function writeByFormat(GdImage $image,string $base,string $format,int $quality,int $width,int $height): array
    {
        return match($format){
            'jpeg'=>$this->writeJpeg($image,$base.'.jpg',$quality,$width,$height),
            'png'=>$this->writePng($image,$base.'.png',$width,$height),
            'webp'=>$this->writeModern($image,$base.'.webp','webp',$quality,$width,$height),
            'avif'=>$this->writeModern($image,$base.'.avif','avif',$quality,$width,$height),
            default=>throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.93414445f679')),
        };
    }

    private function formatFromMime(string $mime): string
    {
        return match($mime){'image/jpeg'=>'jpeg','image/png'=>'png','image/webp'=>'webp','image/avif'=>'avif',default=>'jpeg'};
    }

    /** @return array{format:string,width:int,height:int,key:string,mime:string,bytes:int} */
    private function writeModern(GdImage $image,string $key,string $format,int $quality,int $width,int $height): array
    {
        $function=$format==='avif'?'imageavif':'imagewebp';
        if(!function_exists($function)) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.93414445f679'));
        $path=$this->publicMediaPath($key); $this->ensureDirectory(dirname($path));
        $ok=$format==='avif'?@imageavif($image,$path,$quality):@imagewebp($image,$path,$quality);
        if(!$ok) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.93414445f679'));
        @chmod($path,0644); return ['format'=>$format,'width'=>$width,'height'=>$height,'key'=>$key,'mime'=>'image/'.$format,'bytes'=>(int)filesize($path)];
    }

    /** @return array{format:string,width:int,height:int,key:string,mime:string,bytes:int} */
    private function writeJpeg(GdImage $image,string $key,int $quality,int $width,int $height): array
    {
        $path=$this->publicMediaPath($key); $this->ensureDirectory(dirname($path));
        imageinterlace($image,true);
        if(!@imagejpeg($image,$path,max(1,min(100,$quality)))) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.93414445f679'));
        @chmod($path,0644); return ['format'=>'jpeg','width'=>$width,'height'=>$height,'key'=>$key,'mime'=>'image/jpeg','bytes'=>(int)filesize($path)];
    }

    /** @return array{format:string,width:int,height:int,key:string,mime:string,bytes:int} */
    private function writePng(GdImage $image,string $key,int $width,int $height): array
    {
        $path=$this->publicMediaPath($key); $this->ensureDirectory(dirname($path));
        if(!@imagepng($image,$path,6)) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.93414445f679'));
        @chmod($path,0644); return ['format'=>'png','width'=>$width,'height'=>$height,'key'=>$key,'mime'=>'image/png','bytes'=>(int)filesize($path)];
    }

    /** @param list<array{format:string,width:int,height:int,key:string,mime:string,bytes:int}> $derivatives @return array{format:string,width:int,height:int,key:string,mime:string,bytes:int} */
    private function preferredDerivative(array $derivatives): array
    {
        $pool = array_values(array_filter($derivatives, static fn (array $d): bool => !isset($d['role'])));
        if ($pool === []) {
            $pool = $derivatives;
        }
        usort($pool, static fn (array $a, array $b): int => $b['width'] <=> $a['width']);
        return $pool[0];
    }


    /** @return array{format:string,quality:int,keep_source:bool,presets:array<string,int>,generation:int} */
    private function processingProfile(int $storeId): array
    {
        $saved=$this->revisions->latestValidPayload($storeId,'media','image_processing');

        return MediaImageProfile::normalize(is_array($saved)?$saved:[]);
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
