<?php

declare(strict_types=1);

namespace Commerce\Modules\DigitalProduct\Application;

use Commerce\Core\Id\PublicIdFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final readonly class ProductDigitalAssetService
{
    private const MAX_BYTES = 262_144_000; // 250 MB per file.
    private const ALLOWED_EXTENSIONS = ['pdf','txt','csv','zip','epub','mp3','mp4','webm','jpg','jpeg','png','webp'];
    private const DENIED_MIME_FRAGMENTS = ['php','javascript','html','svg','x-httpd','x-sh','x-executable'];

    public function __construct(
        private Connection $db,
        private PublicIdFactory $publicIds,
        private string $projectDir,
    ) {}

    /** @return array{id:int,public_id:string} */
    public function upload(int $productId, UploadedFile $file, string $title, int $maxDownloads, ?int $accessDays): array
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.digitalproduct.application.productdigitalassetservice.fail_tsyfrovoho_tovaru_zavantazheno_ne_povnistiu'));
        }
        $size = $file->getSize();
        if (!is_int($size) || $size < 1 || $size > self::MAX_BYTES) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.digitalproduct.application.productdigitalassetservice.fail_maie_buty_vid_1_baita_do_250_mb'));
        }
        $title = trim(strip_tags($title));
        if ($title === '' || mb_strlen($title, 'UTF-8') > 255) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.digitalproduct.application.productdigitalassetservice.vkazhit_nazvu_tsyfrovoho_failu_do_255_symvoliv'));
        }
        $maxDownloads = max(1, min(1000, $maxDownloads));
        if ($accessDays !== null) {
            $accessDays = max(1, min(3650, $accessDays));
        }

        if ((string) $this->db->fetchOne('SELECT product_type FROM mc_product WHERE id=? LIMIT 1', [$productId]) !== 'digital') {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.digitalproduct.application.productdigitalassetservice.faily_dlia_zavantazhennia_mozhna_dodavaty_lyshe_do_t'));
        }

        $original = trim((string) $file->getClientOriginalName());
        $extension = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.digitalproduct.application.productdigitalassetservice.tsei_format_tsyfrovoho_failu_ne_dozvolenyi'));
        }
        $path = $file->getPathname();
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string) $finfo->file($path));
        foreach (self::DENIED_MIME_FRAGMENTS as $fragment) {
            if (str_contains($mime, $fragment)) {
                throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.digitalproduct.application.productdigitalassetservice.vykonuvani_html_javascript_svg_ta_serverni_skrypty_z'));
            }
        }
        $checksum = hash_file('sha256', $path);
        if (!is_string($checksum) || strlen($checksum) !== 64) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.digitalproduct.application.productdigitalassetservice.ne_vdalosia_obchyslyty_kontrolnu_sumu_failu'));
        }
        $key = 'digital/' . gmdate('Y/m') . '/' . substr($checksum, 0, 2) . '/' . $checksum . '.' . $extension;
        $target = $this->privatePath($key);
        $this->ensureDirectory(dirname($target));
        if (!is_file($target)) {
            $tmp = $target . '.part-' . bin2hex(random_bytes(8));
            if (!@copy($path, $tmp)) {
                @unlink($tmp);
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.digitalproduct.application.productdigitalassetservice.ne_vdalosia_zapysaty_tsyfrovyi_fail_u_pryvatne_skhov'));
            }
            @chmod($tmp, 0640);
            if (!@rename($tmp, $target)) {
                @unlink($tmp);
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.digitalproduct.application.productdigitalassetservice.ne_vdalosia_atomarno_opublikuvaty_tsyfrovyi_fail'));
            }
        }

        try {
            $uuid = $this->publicIds->generate();
            $now = $this->now();
            $this->db->insert('mc_product_digital_asset', [
                'public_id' => $uuid->toBinary(),
                'product_id' => $productId,
                'title' => $title,
                'original_filename' => mb_substr($original !== '' ? $original : ('download.' . $extension), 0, 255),
                'storage_key' => $key,
                'mime_type' => mb_substr($mime !== '' ? $mime : 'application/octet-stream', 0, 190),
                'bytes' => $size,
                'checksum_sha256' => hex2bin($checksum),
                'max_downloads' => $maxDownloads,
                'access_days' => $accessDays,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return ['id' => (int) $this->db->lastInsertId(), 'public_id' => $uuid->toRfc4122()];
        } catch (\Throwable $e) {
            try {
                if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_product_digital_asset WHERE storage_key=?', [$key]) === 0) {
                    @unlink($target);
                }
            } catch (\Throwable) {}
            throw $e;
        }
    }

    public function deactivate(int $productId, string $publicId): void
    {
        $binary = Uuid::fromString($publicId)->toBinary();
        $changed = $this->db->executeStatement(
            "UPDATE mc_product_digital_asset SET status='inactive',updated_at=? WHERE product_id=? AND public_id=? AND status='active'",
            [$this->now(), $productId, $binary],
        );
        if ($changed !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.digitalproduct.application.productdigitalassetservice.aktyvnyi_tsyfrovyi_fail_ne_znaideno'));
        }
    }

    /** @return list<array<string,mixed>> */
    public function forProduct(int $productId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id,public_id,title,original_filename,mime_type,bytes,max_downloads,access_days,status,created_at FROM mc_product_digital_asset WHERE product_id=? ORDER BY status ASC,id DESC',
            [$productId],
        );
        foreach ($rows as &$row) {
            $row['public_id'] = Uuid::fromBinary((string) $row['public_id'])->toRfc4122();
            $row['bytes'] = (int) $row['bytes'];
            $row['max_downloads'] = (int) $row['max_downloads'];
            $row['access_days'] = $row['access_days'] === null ? null : (int) $row['access_days'];
        }
        unset($row);
        return $rows;
    }

    private function privatePath(string $key): string
    {
        $key = str_replace('\\', '/', trim($key));
        if ($key === '' || str_contains($key, '..') || str_starts_with($key, '/')) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.c12a50c4def9'));
        }
        return rtrim($this->projectDir, '/\\') . '/var/storage/' . $key;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.digitalproduct.application.productdigitalassetservice.ne_vdalosia_stvoryty_pryvatne_skhovyshche_tsyfrovykh'));
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
