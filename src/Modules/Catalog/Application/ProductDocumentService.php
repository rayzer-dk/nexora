<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Commerce\Core\Id\PublicIdFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Strict non-executable product document upload. Only PDF and plain text are
 * accepted and the storage name is content-addressed, never user-controlled.
 */
final readonly class ProductDocumentService
{
    private const MAX_BYTES = 20_971_520;

    public function __construct(
        private Connection $db,
        private PublicIdFactory $publicIds,
        private string $projectDir,
    ) {
    }

    /** @return array{id:int,public_id:string} */
    public function uploadAndAttach(
        int $productId,
        UploadedFile $file,
        string $title,
        ?string $locale,
        string $documentType = 'document',
        int $sortOrder = 100,
    ): array {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productdocumentservice.dokument_ne_buv_zavantazhenyi_povnistiu'));
        }
        $size = $file->getSize();
        if (!is_int($size) || $size < 1 || $size > self::MAX_BYTES) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productdocumentservice.dokument_maie_buty_ne_bilshe_20_mb'));
        }
        $title = trim(strip_tags($title));
        if ($title === '' || mb_strlen($title, 'UTF-8') > 255) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productdocumentservice.vkazhit_nazvu_dokumenta_do_255_symvoliv'));
        }
        if ($locale !== null) {
            $locale = trim($locale);
            if ($locale === '' || preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $locale) !== 1) {
                throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productdocumentservice.nekorektna_mova_dokumenta'));
            }
        }
        $documentType = trim($documentType);
        if (!in_array($documentType, ['document', 'manual', 'certificate', 'sds', 'datasheet', 'warranty'], true)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.http.adminorderdocumentcontroller.nevidomyi_typ_dokumenta'));
        }
        $sortOrder = max(0, min(65535, $sortOrder));

        $path = $file->getPathname();
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productdocumentservice.ne_vdalosia_prochytaty_dokument'));
        }
        if (str_contains($raw, "\0") && !str_starts_with($raw, '%PDF-')) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productdocumentservice.dviikovyi_fail_ne_ie_dozvolenym_dokumentom'));
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string) $finfo->file($path));
        $extension = null;
        if ($mime === 'application/pdf' && str_starts_with($raw, '%PDF-')) {
            $extension = 'pdf';
        } elseif (in_array($mime, ['text/plain', 'text/x-readme'], true) && !str_contains($raw, "\0")) {
            $extension = 'txt';
            $mime = 'text/plain';
        }
        if ($extension === null) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productdocumentservice.dozvoleni_lyshe_spravzhni_pdf_ta_txt_dokumenty_html_'));
        }

        $checksum = hash('sha256', $raw);
        $key = 'documents/' . gmdate('Y/m') . '/' . substr($checksum, 0, 2) . '/' . $checksum . '.' . $extension;
        $target = $this->publicMediaPath($key);
        $this->ensureDirectory(dirname($target));
        if (!is_file($target)) {
            $tmp = $target . '.part-' . bin2hex(random_bytes(8));
            if (@file_put_contents($tmp, $raw, LOCK_EX) !== strlen($raw)) {
                @unlink($tmp);
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productdocumentservice.ne_vdalosia_zapysaty_dokument_u_skhovyshche'));
            }
            @chmod($tmp, 0644);
            if (!@rename($tmp, $target)) {
                @unlink($tmp);
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productdocumentservice.ne_vdalosia_atomarno_opublikuvaty_dokument'));
            }
        }

        try {
            return $this->db->transactional(function (Connection $db) use ($productId, $title, $locale, $documentType, $sortOrder, $key, $mime, $raw, $checksum): array {
                if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_product WHERE id=?', [$productId]) !== 1) {
                    throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerinquiryservice.tovar_ne_znaideno'));
                }
                if ($locale !== null && (int) $db->fetchOne('SELECT COUNT(*) FROM mc_locale WHERE code=? AND enabled=1', [$locale]) !== 1) {
                    throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productdocumentservice.mova_dokumenta_ne_aktyvna'));
                }

                $asset = $db->fetchAssociative('SELECT id,public_id FROM mc_media_asset WHERE storage_key_hash=? LIMIT 1', [hash('sha256', $key, true)]);
                if (!is_array($asset)) {
                    $assetUuid = $this->publicIds->generate();
                    $db->insert('mc_media_asset', [
                        'public_id' => $assetUuid->toBinary(),
                        'storage_key' => $key,
                        'storage_key_hash' => hash('sha256', $key, true),
                        'mime_type' => $mime,
                        'bytes' => strlen($raw),
                        'width' => null,
                        'height' => null,
                        'checksum_sha256' => hex2bin($checksum),
                        'metadata' => json_encode(['kind' => 'product_document', 'safe_extension' => pathinfo($key, PATHINFO_EXTENSION)], JSON_THROW_ON_ERROR),
                        'created_at' => $this->now(),
                    ]);
                    $assetId = (int) $db->lastInsertId();
                } else {
                    $assetId = (int) $asset['id'];
                }

                $documentUuid = $this->publicIds->generate();
                $now = $this->now();
                $db->insert('mc_product_document', [
                    'public_id' => $documentUuid->toBinary(),
                    'product_id' => $productId,
                    'media_id' => $assetId,
                    'locale' => $locale,
                    'document_type' => $documentType,
                    'title' => $title,
                    'document_version' => null,
                    'sort_order' => $sortOrder,
                    'visible' => 1,
                    'valid_from' => null,
                    'valid_to' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                return ['id' => (int) $db->lastInsertId(), 'public_id' => $documentUuid->toRfc4122()];
            });
        } catch (\Throwable $e) {
            // Keep a content-addressed file only if another database record references it.
            try {
                if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_media_asset WHERE storage_key_hash=?', [hash('sha256', $key, true)]) === 0) {
                    @unlink($target);
                }
            } catch (\Throwable) {
                // Cleanup failure is non-fatal and will be handled by orphan maintenance.
            }
            throw $e;
        }
    }

    public function remove(int $productId, string $documentPublicId): void
    {
        $binary = Uuid::fromString($documentPublicId)->toBinary();
        $this->db->transactional(function (Connection $db) use ($productId, $binary): void {
            $row = $db->fetchAssociative('SELECT id,media_id FROM mc_product_document WHERE product_id=? AND public_id=? FOR UPDATE', [$productId, $binary]);
            if (!is_array($row)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.application.orderdocumentservice.dokument_ne_znaideno'));
            }
            $db->delete('mc_product_document', ['id' => (int) $row['id']]);
            // The media asset/file is intentionally not deleted here. Shared assets and
            // interrupted requests remain safe; Media orphan cleanup verifies references first.
        });
    }

    private function publicMediaPath(string $key): string
    {
        $key = str_replace('\\', '/', trim($key));
        if ($key === '' || str_contains($key, '..') || str_starts_with($key, '/')) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a73892f7baf7'));
        }
        return rtrim($this->projectDir, '/\\') . '/public/media/' . $key;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productdocumentservice.ne_vdalosia_stvoryty_kataloh_dokumentiv'));
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
