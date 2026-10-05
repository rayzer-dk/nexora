<?php

declare(strict_types=1);

namespace Commerce\Modules\Quality\Application;

use Commerce\Core\Configuration\SystemSettingStore;
use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;

/**
 * Problems that are cheap to fix now and expensive later: the certificate that is about to expire, a nearly full disk,
 * no recent backup, goods that will run out within days at the current speed of sales.
 * The certificate is read by {@see refreshCertificate()} (cron or a button), never while a page is shown.
 */
final class EarlyWarningService
{
    private const CERT_KEY = 'warnings.certificate';

    public function __construct(
        private readonly Connection $db,
        private readonly SystemSettingStore $store,
        private readonly string $publicUrl,
        private readonly string $projectDir,
    ) {
    }

    /** @return list<array{code:string,level:string,text:string,url:string}> */
    public function warnings(int $storeId): array
    {
        $out = [];

        $cert = $this->store->getArray(self::CERT_KEY);
        if (is_array($cert) && isset($cert['days'])) {
            $days = (int) $cert['days'];
            if ($days <= 21) {
                $out[] = ['code' => 'certificate', 'level' => $days <= 7 ? 'fail' : 'warn', 'text' => CanonicalUiText::get('admin.warn.certificate', ['host' => (string) ($cert['host'] ?? ''), 'days' => (string) max(0, $days)]), 'url' => '/admin/system/early-warnings'];
            }
        }

        $total = @disk_total_space($this->projectDir);
        $free = @disk_free_space($this->projectDir);
        if (is_float($total) && is_float($free) && $total > 0 && ($free / $total < 0.10 || $free < 500 * 1024 * 1024)) {
            $out[] = ['code' => 'disk', 'level' => $free < 200 * 1024 * 1024 ? 'fail' : 'warn', 'text' => CanonicalUiText::get('admin.warn.disk', ['free' => $this->size($free), 'percent' => (string) round($free / $total * 100)]), 'url' => '/admin/system/stability'];
        }

        try {
            $created = $this->db->fetchOne("SELECT created_at FROM mc_recovery_snapshot WHERE status IN ('ready','verified') ORDER BY id DESC LIMIT 1");
            $age = is_string($created) && $created !== '' ? (int) floor((time() - strtotime($created)) / 86400) : null;
            if ($age === null || $age >= 7) {
                $out[] = ['code' => 'backup', 'level' => 'warn', 'text' => $age === null ? CanonicalUiText::get('admin.warn.backup_none') : CanonicalUiText::get('admin.warn.backup_old', ['days' => (string) $age]), 'url' => '/admin/system/stability'];
            }
        } catch (\Throwable) {
        }

        $run = $this->runOut($storeId);
        if ($run !== []) {
            $names = implode(', ', array_map(static fn (array $r): string => $r['name'] . ' (' . $r['days'] . ')', array_slice($run, 0, 3)));
            $out[] = ['code' => 'stock', 'level' => 'warn', 'text' => CanonicalUiText::get('admin.warn.stock', ['count' => (string) count($run), 'names' => $names]), 'url' => '/admin/analytics'];
        }

        return $out;
    }

    /**
     * Goods that sell at a steady pace and will be gone within a week at that pace: [name, days left].
     *
     * @return list<array{name:string,days:int,stock:int,per_day:float}>
     */
    public function runOut(int $storeId, int $withinDays = 7): array
    {
        try {
            $rows = $this->db->fetchAllAssociative(
                "SELECT v.id,COALESCE(MIN(pt.name),MIN(v.sku)) name,SUM(sl.stocked_quantity-sl.reserved_quantity-sl.safety_stock) stock,
                        (SELECT SUM(oi.quantity) FROM mc_sales_order_item oi JOIN mc_sales_order o ON o.id=oi.order_id WHERE oi.variant_id=v.id AND o.store_id=? AND o.created_at>=? AND o.status NOT IN ('cancelled','expired')) sold
                 FROM mc_product_variant v
                 JOIN mc_product p ON p.id=v.product_id AND p.status='published'
                 JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=?
                 JOIN mc_variant_inventory_item vii ON vii.variant_id=v.id
                 JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id
                 LEFT JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=sp.store_id AND pt.is_fallback=0
                 WHERE v.manage_inventory=1 AND v.allow_backorder=0
                 GROUP BY v.id HAVING stock>0 AND sold>=3",
                [$storeId, gmdate('Y-m-d H:i:s', time() - 30 * 86400), $storeId],
            );
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $perDay = (float) $r['sold'] / 30;
            $days = (int) ceil((float) $r['stock'] / max(0.01, $perDay));
            if ($days <= $withinDays) {
                $out[] = ['name' => (string) $r['name'], 'days' => $days, 'stock' => (int) $r['stock'], 'per_day' => round($perDay, 2)];
            }
        }
        usort($out, static fn (array $a, array $b): int => $a['days'] <=> $b['days']);

        return array_slice($out, 0, 30);
    }

    /** Opens a TLS connection to the public address of the shop and stores how many days the certificate has left. @return array{host:string,days:int}|null */
    public function refreshCertificate(): ?array
    {
        $parts = parse_url($this->publicUrl);
        $host = (string) ($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $this->store->setArray(self::CERT_KEY, []);

            return null;
        }
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $errno = 0;
        $err = '';
        $fp = @stream_socket_client('ssl://' . $host . ':' . (int) ($parts['port'] ?? 443), $errno, $err, 4, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) {
            return null;
        }
        $params = stream_context_get_params($fp);
        fclose($fp);
        $cert = isset($params['options']['ssl']['peer_certificate']) ? openssl_x509_parse($params['options']['ssl']['peer_certificate']) : false;
        if (!is_array($cert) || !isset($cert['validTo_time_t'])) {
            return null;
        }
        $result = ['host' => $host, 'days' => (int) floor(((int) $cert['validTo_time_t'] - time()) / 86400), 'checked_at' => gmdate('c')];
        $this->store->setArray(self::CERT_KEY, $result);

        return ['host' => $host, 'days' => $result['days']];
    }

    /** @return array{host:string,days:int,checked_at:string}|null */
    public function certificate(): ?array
    {
        $c = $this->store->getArray(self::CERT_KEY);

        return is_array($c) && isset($c['days']) ? ['host' => (string) ($c['host'] ?? ''), 'days' => (int) $c['days'], 'checked_at' => (string) ($c['checked_at'] ?? '')] : null;
    }

    private function size(float $bytes): string
    {
        return $bytes >= 1073741824 ? round($bytes / 1073741824, 1) . ' GB' : round($bytes / 1048576) . ' MB';
    }
}
