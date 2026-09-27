<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

use Commerce\Core\Id\PublicIdFactory;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class MediaVideoService
{
    private const MAX_UPLOAD_BYTES = 104857600; // 100 MB

    public function __construct(
        private Connection $db,
        private PublicIdFactory $publicIds,
        private string $projectDir,
    ) {}

    public function upload(UploadedFile $file): ImageUploadResult
    {
        if (!$file->isValid()) throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.mediavideoservice.zavantazhennia_video_ne_zavershylosia_uspishno'));
        $bytes = $file->getSize();
        if (!is_int($bytes) || $bytes < 1 || $bytes > self::MAX_UPLOAD_BYTES) throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.mediavideoservice.video_maie_buty_ne_bilshe_100_mb'));
        $source = $file->getPathname();
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string) $finfo->file($source));
        $extension = match ($mime) {
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            default => throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.mediavideoservice.dozvoleni_lyshe_mp4_ta_webm_video')),
        };
        $checksum = hash_file('sha256', $source);
        if (!is_string($checksum) || $checksum === '') throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.mediavideoservice.ne_vdalosia_obchyslyty_checksum_video'));
        $key = 'video/' . gmdate('Y/m') . '/' . substr($checksum, 0, 2) . '/' . $checksum . '.' . $extension;
        $hash = hash('sha256', $key, true);
        $existing = $this->db->fetchAssociative('SELECT id,public_id,storage_key FROM mc_media_asset WHERE storage_key_hash=? LIMIT 1', [$hash]);
        if (is_array($existing)) {
            return new ImageUploadResult((int)$existing['id'], \Symfony\Component\Uid\Uuid::fromBinary((string)$existing['public_id'])->toRfc4122(), '/media/'.ltrim((string)$existing['storage_key'],'/'), (string)$existing['storage_key'], 0, 0, []);
        }
        $target = $this->path($key); $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.mediavideoservice.ne_vdalosia_stvoryty_kataloh_video'));
        $tmp = $target . '.tmp-' . bin2hex(random_bytes(4));
        if (!@copy($source, $tmp)) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.mediavideoservice.ne_vdalosia_zberehty_video'));
        @chmod($tmp, 0644);
        if (!@rename($tmp, $target)) { @unlink($tmp); throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.media.application.mediavideoservice.ne_vdalosia_aktyvuvaty_videofail')); }
        $uuid = $this->publicIds->generate();
        try {
            $this->db->insert('mc_media_asset', [
                'public_id'=>$uuid->toBinary(), 'storage_key'=>$key, 'storage_key_hash'=>$hash,
                'mime_type'=>$mime, 'bytes'=>(int)filesize($target), 'width'=>null, 'height'=>null,
                'checksum_sha256'=>hash_file('sha256', $target, true),
                'metadata'=>json_encode(['source_mime'=>$mime,'source_checksum'=>$checksum,'media_type'=>'video'], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
                'created_at'=>gmdate('Y-m-d H:i:s.u'),
            ]);
        } catch (\Throwable $e) { @unlink($target); throw $e; }
        return new ImageUploadResult((int)$this->db->lastInsertId(), $uuid->toRfc4122(), '/media/'.$key, $key, 0, 0, []);
    }

    private function path(string $key): string
    {
        if ($key === '' || str_contains($key, '..') || str_starts_with($key, '/')) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5c342b068b42'));
        return rtrim($this->projectDir, '/\\') . '/public/media/' . $key;
    }
}
