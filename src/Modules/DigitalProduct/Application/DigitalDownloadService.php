<?php

declare(strict_types=1);

namespace Commerce\Modules\DigitalProduct\Application;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final readonly class DigitalDownloadService
{
    public function __construct(private Connection $db, private string $projectDir) {}

    /** @return list<array<string,mixed>> */
    public function forOrder(int $customerId, int $storeId, string $orderPublicId): array
    {
        try { $orderBinary = Uuid::fromString($orderPublicId)->toBinary(); } catch (\Throwable) { return []; }
        $rows = $this->db->fetchAllAssociative(
            "SELECT e.public_id,e.status,e.max_downloads,e.download_count,e.activated_at,e.expires_at,
                    a.title,a.original_filename,a.bytes,oi.name AS product_name
             FROM mc_digital_entitlement e
             JOIN mc_sales_order o ON o.id=e.order_id
             JOIN mc_sales_order_item oi ON oi.id=e.order_item_id
             JOIN mc_product_digital_asset a ON a.id=e.asset_id
             WHERE o.public_id=? AND o.customer_id=? AND o.store_id=?
             ORDER BY oi.id,a.id",
            [$orderBinary, $customerId, $storeId],
        );
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        foreach ($rows as &$row) {
            $row['public_id'] = Uuid::fromBinary((string) $row['public_id'])->toRfc4122();
            $row['max_downloads'] = (int) $row['max_downloads'];
            $row['download_count'] = (int) $row['download_count'];
            $row['bytes'] = (int) $row['bytes'];
            $expired = $row['expires_at'] !== null && new DateTimeImmutable((string) $row['expires_at'], new DateTimeZone('UTC')) <= $now;
            $row['available'] = (string) $row['status'] === 'active' && !$expired && (int) $row['download_count'] < (int) $row['max_downloads'];
        }
        unset($row);
        return $rows;
    }

    /** @return array{path:string,filename:string,mime:string} */
    public function claim(int $customerId, int $storeId, string $entitlementPublicId): array
    {
        $binary = Uuid::fromString($entitlementPublicId)->toBinary();
        return $this->db->transactional(function (Connection $db) use ($customerId, $storeId, $binary): array {
            $row = $db->fetchAssociative(
                "SELECT e.*,a.storage_key,a.original_filename,a.mime_type,o.payment_status
                 FROM mc_digital_entitlement e
                 JOIN mc_sales_order o ON o.id=e.order_id
                 JOIN mc_product_digital_asset a ON a.id=e.asset_id
                 WHERE e.public_id=? AND e.customer_id=? AND e.store_id=? FOR UPDATE",
                [$binary, $customerId, $storeId],
            );
            if (!is_array($row)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.digitalproduct.application.digitaldownloadservice.fail_nedostupnyi_dlia_tsoho_oblikovoho_zapysu'));
            if ((string) $row['payment_status'] !== 'paid' || (string) $row['status'] !== 'active') {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.digitalproduct.application.digitaldownloadservice.zavantazhennia_stane_dostupnym_pislia_pidtverdzhenoi'));
            }
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if ($row['expires_at'] !== null && new DateTimeImmutable((string) $row['expires_at'], new DateTimeZone('UTC')) <= $now) {
                $db->update('mc_digital_entitlement', ['status' => 'expired', 'updated_at' => $now->format('Y-m-d H:i:s.u')], ['id' => (int) $row['id']]);
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.digitalproduct.application.digitaldownloadservice.strok_dostupu_do_tsoho_failu_zavershyvsia'));
            }
            if ((int) $row['download_count'] >= (int) $row['max_downloads']) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.digitalproduct.application.digitaldownloadservice.limit_zavantazhen_tsoho_failu_vycherpano'));
            }
            $path = $this->privatePath((string) $row['storage_key']);
            if (!is_file($path) || !is_readable($path)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.digitalproduct.application.digitaldownloadservice.fail_tymchasovo_nedostupnyi_u_skhovyshchi'));
            }
            $db->executeStatement(
                'UPDATE mc_digital_entitlement SET download_count=download_count+1,last_downloaded_at=?,updated_at=? WHERE id=?',
                [$now->format('Y-m-d H:i:s.u'), $now->format('Y-m-d H:i:s.u'), (int) $row['id']],
            );
            return [
                'path' => $path,
                'filename' => $this->safeFilename((string) $row['original_filename']),
                'mime' => (string) $row['mime_type'],
            ];
        });
    }

    private function privatePath(string $key): string
    {
        $key = str_replace('\\', '/', trim($key));
        if ($key === '' || str_contains($key, '..') || str_starts_with($key, '/')) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a9f063fb7465'));
        return rtrim($this->projectDir, '/\\') . '/var/storage/' . $key;
    }

    private function safeFilename(string $filename): string
    {
        $filename = trim((string) preg_replace('/[\\x00-\\x1F\\x7F\\\\\/]+/u', '_', $filename));
        return $filename !== '' ? mb_substr($filename, 0, 180) : 'download.bin';
    }
}
