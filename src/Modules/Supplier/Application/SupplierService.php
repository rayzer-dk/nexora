<?php

declare(strict_types=1);

namespace Commerce\Modules\Supplier\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Core\Security\SecretVault;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Keeps prices and stock in line with a supplier's feed. A run reads the feed, finds the shop's variants by SKU (or barcode / part number),
 * works out the new price (supplier price + markup, rounded) and stock, and lists only what differs. In "preview" mode the owner reviews and
 * applies the changes; in "auto" mode they are applied at once. Products the shop does not have are listed separately and can be exported as
 * a CSV for the normal catalogue import.
 */
final class SupplierService
{
    private const MAX_FEED_BYTES = 100_000_000;
    private const MAX_CHANGES = 5000;
    private const MAX_NEW = 1000;
    private const CTX = 'supplier.http_password';

    public function __construct(
        private readonly Connection $db,
        private readonly SupplierFeedParser $parser,
        private readonly HttpClientInterface $http,
        private readonly SecretVault $vault,
        private readonly bool $allowPrivateHosts = false,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function all(int $storeId): array
    {
        return $this->db->fetchAllAssociative('SELECT s.*,(SELECT COUNT(*) FROM mc_supplier_item i WHERE i.supplier_id=s.id AND i.status=\'pending\' AND i.action<>\'new\') AS pending FROM mc_supplier s WHERE s.store_id=? ORDER BY s.name', [$storeId]);
    }

    /** @return array<string,mixed>|null */
    public function get(int $storeId, int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM mc_supplier WHERE id=? AND store_id=?', [$id, $storeId]);

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $in */
    public function save(int $storeId, ?int $id, array $in): int
    {
        $name = trim(mb_substr(strip_tags((string) ($in['name'] ?? '')), 0, 190, 'UTF-8'));
        $url = trim((string) ($in['feed_url'] ?? ''));
        if ($name === '' || !$this->safeUrl($url)) {
            throw new \DomainException(CanonicalUiText::get('supplier.runtime.invalid'));
        }
        $format = in_array($in['format'] ?? '', SupplierFeedParser::FORMATS, true) ? (string) $in['format'] : 'yml';
        $mapping = [];
        foreach (['item', 'sku', 'name', 'price', 'stock', 'gtin'] as $key) {
            $value = trim(mb_substr(strip_tags((string) ($in['map_' . $key] ?? '')), 0, 60, 'UTF-8'));
            if ($value !== '') {
                $mapping[$key] = $value;
            }
        }
        $now = $this->now();
        $data = [
            'name' => $name,
            'feed_url' => mb_substr($url, 0, 1000, 'UTF-8'),
            'format' => $format,
            'http_user' => trim((string) ($in['http_user'] ?? '')) ?: null,
            'match_by' => in_array($in['match_by'] ?? '', ['sku', 'gtin', 'mpn'], true) ? (string) $in['match_by'] : 'sku',
            'sku_prefix' => mb_substr(trim((string) ($in['sku_prefix'] ?? '')), 0, 40, 'UTF-8'),
            'markup_percent' => max(-90, min(1000, (float) str_replace(',', '.', (string) ($in['markup_percent'] ?? '0')))),
            'rounding' => in_array($in['rounding'] ?? '', ['none', 'whole', 'tens'], true) ? (string) $in['rounding'] : 'none',
            'apply_price' => !empty($in['apply_price']) ? 1 : 0,
            'apply_stock' => !empty($in['apply_stock']) ? 1 : 0,
            'stock_when_available' => max(0, min(100000, (int) ($in['stock_when_available'] ?? 5))),
            'mode' => ($in['mode'] ?? '') === 'auto' ? 'auto' : 'preview',
            'interval_minutes' => max(15, min(10080, (int) ($in['interval_minutes'] ?? 360))),
            'mapping_json' => $mapping === [] ? null : json_encode($mapping, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'enabled' => !empty($in['enabled']) ? 1 : 0,
            'updated_at' => $now,
        ];
        $password = (string) ($in['http_pass'] ?? '');
        if ($password !== '') {
            $data['http_pass_enc'] = $this->vault->encrypt($password, self::CTX);
        } elseif (!empty($in['clear_pass'])) {
            $data['http_pass_enc'] = null;
        }
        if ($id !== null && $this->get($storeId, $id) !== null) {
            $this->db->update('mc_supplier', $data, ['id' => $id, 'store_id' => $storeId]);

            return $id;
        }
        $this->db->insert('mc_supplier', $data + ['store_id' => $storeId, 'created_at' => $now]);

        return (int) $this->db->lastInsertId();
    }

    public function delete(int $storeId, int $id): void
    {
        if ($this->get($storeId, $id) === null) {
            return;
        }
        $this->db->executeStatement('DELETE FROM mc_supplier_item WHERE supplier_id=?', [$id]);
        $this->db->executeStatement('DELETE FROM mc_supplier_run WHERE supplier_id=?', [$id]);
        $this->db->delete('mc_supplier', ['id' => $id, 'store_id' => $storeId]);
    }

    /** Runs every enabled supplier whose interval has elapsed. @return int suppliers processed */
    public function runDue(): int
    {
        $due = $this->db->fetchAllAssociative(
            "SELECT id,store_id FROM mc_supplier WHERE enabled=1 AND (last_run_at IS NULL OR last_run_at <= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL interval_minutes MINUTE)) ORDER BY id LIMIT 20",
        );
        foreach ($due as $row) {
            try {
                $this->run((int) $row['store_id'], (int) $row['id']);
            } catch (\Throwable) {
                // each supplier records its own failure
            }
        }

        return count($due);
    }

    /** @return array{rows:int,matched:int,changed:int,applied:int,new:int} */
    public function run(int $storeId, int $supplierId): array
    {
        $supplier = $this->get($storeId, $supplierId);
        if ($supplier === null) {
            throw new \DomainException(CanonicalUiText::get('supplier.runtime.invalid'));
        }
        $now = $this->now();
        $this->db->insert('mc_supplier_run', ['supplier_id' => $supplierId, 'started_at' => $now, 'status' => 'running']);
        $runId = (int) $this->db->lastInsertId();
        $tmp = tempnam(sys_get_temp_dir(), 'supplier');
        try {
            $this->download($supplier, (string) $tmp);
            $stats = $this->process($supplier, $runId, (string) $tmp);
            $this->finish($supplierId, $runId, 'ok', '', $stats);

            return $stats;
        } catch (\Throwable $e) {
            $message = mb_substr($e instanceof \DomainException ? $e->getMessage() : CanonicalUiText::get('supplier.runtime.failed'), 0, 480, 'UTF-8');
            $this->finish($supplierId, $runId, 'failed', $message, ['rows' => 0, 'matched' => 0, 'changed' => 0, 'applied' => 0, 'new' => 0]);
            throw $e instanceof \DomainException ? $e : new \DomainException($message, 0, $e);
        } finally {
            if (is_string($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public function items(int $supplierId, string $kind, int $limit = 200): array
    {
        $where = $kind === 'new' ? "action='new'" : "action<>'new'";

        return $this->db->fetchAllAssociative(
            "SELECT * FROM mc_supplier_item WHERE supplier_id=? AND status='pending' AND {$where} ORDER BY id LIMIT " . max(1, min(1000, $limit)),
            [$supplierId],
        );
    }

    public function pendingCount(int $supplierId, string $kind): int
    {
        return (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_supplier_item WHERE supplier_id=? AND status='pending' AND " . ($kind === 'new' ? "action='new'" : "action<>'new'"), [$supplierId]);
    }

    /** @return list<array<string,mixed>> */
    public function runs(int $supplierId, int $limit = 10): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM mc_supplier_run WHERE supplier_id=? ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)), [$supplierId]);
    }

    /**
     * @param list<int>|null $ids null = every pending change of the supplier
     * @param 'both'|'price'|'stock' $what
     */
    public function applyItems(int $storeId, int $marketId, int $supplierId, ?array $ids, string $what): int
    {
        if ($this->get($storeId, $supplierId) === null) {
            return 0;
        }
        $sql = "SELECT * FROM mc_supplier_item WHERE supplier_id=? AND status='pending' AND action<>'new'";
        $params = [$supplierId];
        if ($ids !== null) {
            if ($ids === []) {
                return 0;
            }
            $sql .= ' AND id IN (' . implode(',', array_map('intval', $ids)) . ')';
        }
        $count = 0;
        foreach ($this->db->fetchAllAssociative($sql . ' LIMIT 5000', $params) as $item) {
            if ($this->applyOne($storeId, $marketId, $item, $what)) {
                ++$count;
            }
        }

        return $count;
    }

    /** @param list<int> $ids */
    public function skipItems(int $supplierId, array $ids): void
    {
        if ($ids !== []) {
            $this->db->executeStatement("UPDATE mc_supplier_item SET status='skipped' WHERE supplier_id=? AND status='pending' AND id IN (" . implode(',', array_map('intval', $ids)) . ')', [$supplierId]);
        }
    }

    /** Offers the shop does not have, in the column layout of the catalogue CSV import. */
    public function newOffersCsv(int $storeId, int $supplierId): string
    {
        $supplier = $this->get($storeId, $supplierId);
        $fh = fopen('php://temp', 'w+');
        if ($supplier === null || $fh === false) {
            return '';
        }
        fputcsv($fh, ['sku', 'name', 'price', 'stock_quantity'], ',', '"', '');
        foreach ($this->db->fetchAllAssociative("SELECT * FROM mc_supplier_item WHERE supplier_id=? AND action='new' AND status='pending' ORDER BY id LIMIT 5000", [$supplierId]) as $row) {
            fputcsv($fh, [(string) $supplier['sku_prefix'] . $row['external_sku'], $row['name'], number_format((int) $row['new_price_minor'] / 100, 2, '.', ''), $row['new_stock'] !== null ? (string) (float) $row['new_stock'] : '0'], ',', '"', '');
        }
        rewind($fh);

        return (string) stream_get_contents($fh);
    }

    /** @param array<string,mixed> $item */
    private function applyOne(int $storeId, int $marketId, array $item, string $what): bool
    {
        $variantId = (int) ($item['variant_id'] ?? 0);
        if ($variantId < 1) {
            return false;
        }
        $done = false;
        $now = $this->now();
        if (in_array($what, ['both', 'price'], true) && $item['new_price_minor'] !== null) {
            $priceId = $this->db->fetchOne(
                "SELECT id FROM mc_price WHERE variant_id=? AND store_id=? AND market_id=? AND customer_group='default' AND price_list_id IS NULL AND max_quantity IS NULL AND starts_at IS NULL AND ends_at IS NULL ORDER BY priority ASC,min_quantity ASC,id DESC LIMIT 1",
                [$variantId, $storeId, $marketId],
            );
            if ($priceId !== false) {
                $this->db->executeStatement(
                    'UPDATE mc_price SET amount_minor=?,compare_at_minor=IF(compare_at_minor IS NOT NULL AND compare_at_minor<=?,NULL,compare_at_minor),updated_at=? WHERE id=?',
                    [(int) $item['new_price_minor'], (int) $item['new_price_minor'], $now, (int) $priceId],
                );
                $done = true;
            }
        }
        if (in_array($what, ['both', 'stock'], true) && $item['new_stock'] !== null) {
            $changed = $this->db->executeStatement(
                'UPDATE mc_stock_level sl
                 JOIN mc_variant_inventory_item vii ON vii.inventory_item_id=sl.inventory_item_id AND vii.variant_id=?
                 JOIN (SELECT mil.location_id FROM mc_market_inventory_location mil WHERE mil.market_id=? ORDER BY mil.priority,mil.location_id LIMIT 1) loc ON loc.location_id=sl.location_id
                 SET sl.stocked_quantity=?,sl.row_version=sl.row_version+1,sl.updated_at=?',
                [$variantId, $marketId, number_format((float) $item['new_stock'], 6, '.', ''), $now],
            );
            $done = $done || $changed > 0;
        }
        $this->db->update('mc_supplier_item', ['status' => $done ? 'applied' : 'skipped'], ['id' => (int) $item['id']]);

        return $done;
    }

    /**
     * @param array<string,mixed> $supplier
     * @return array{rows:int,matched:int,changed:int,applied:int,new:int}
     */
    private function process(array $supplier, int $runId, string $path): array
    {
        $supplierId = (int) $supplier['id'];
        $storeId = (int) $supplier['store_id'];
        $market = $this->db->fetchAssociative("SELECT id FROM mc_market WHERE store_id=? AND status='active' ORDER BY id LIMIT 1", [$storeId]);
        $marketId = is_array($market) ? (int) $market['id'] : 0;
        $matchColumn = ['sku' => 'v.sku', 'gtin' => 'v.gtin', 'mpn' => 'v.mpn'][(string) $supplier['match_by']] ?? 'v.sku';
        $variants = [];
        $rows = $this->db->fetchAllAssociative(
            "SELECT v.id AS variant_id,{$matchColumn} AS code,
                    COALESCE((SELECT pt.name FROM mc_product_translation pt WHERE pt.product_id=p.id AND pt.store_id=:store ORDER BY pt.id LIMIT 1),'') AS name,
                    (SELECT pr.amount_minor FROM mc_price pr WHERE pr.variant_id=v.id AND pr.store_id=:store AND pr.market_id=:market AND pr.customer_group='default' AND pr.price_list_id IS NULL AND pr.max_quantity IS NULL AND pr.starts_at IS NULL AND pr.ends_at IS NULL ORDER BY pr.priority,pr.min_quantity,pr.id DESC LIMIT 1) AS price_minor,
                    (SELECT sl.stocked_quantity FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_market_inventory_location mil ON mil.location_id=sl.location_id AND mil.market_id=:market WHERE vii.variant_id=v.id ORDER BY mil.priority,sl.location_id LIMIT 1) AS stock
             FROM mc_product_variant v JOIN mc_product p ON p.id=v.product_id JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=:store
             WHERE {$matchColumn} IS NOT NULL AND {$matchColumn}<>''",
            ['store' => $storeId, 'market' => $marketId],
        );
        foreach ($rows as $r) {
            $variants[mb_strtolower((string) $r['code'], 'UTF-8')] = $r;
        }
        $mapping = is_string($supplier['mapping_json'] ?? null) ? (array) json_decode((string) $supplier['mapping_json'], true) : [];
        $stats = ['rows' => 0, 'matched' => 0, 'changed' => 0, 'applied' => 0, 'new' => 0];
        $now = $this->now();
        $auto = $supplier['mode'] === 'auto';
        $prefix = (string) $supplier['sku_prefix'];
        $this->db->executeStatement("DELETE FROM mc_supplier_item WHERE supplier_id=? AND status='pending'", [$supplierId]);
        foreach ($this->parser->parse($path, (string) $supplier['format'], $mapping) as $offer) {
            ++$stats['rows'];
            $key = mb_strtolower($supplier['match_by'] === 'sku' ? $prefix . $offer['sku'] : ($supplier['match_by'] === 'gtin' ? $offer['gtin'] : $offer['sku']), 'UTF-8');
            $newPrice = $this->price($offer['price'], (float) $supplier['markup_percent'], (string) $supplier['rounding']);
            $newStock = $offer['stock'] ?? ($offer['available'] === null ? null : ($offer['available'] ? (float) $supplier['stock_when_available'] : 0.0));
            $variant = $variants[$key] ?? null;
            if ($variant === null) {
                if ($stats['new'] < self::MAX_NEW) {
                    $this->db->insert('mc_supplier_item', ['supplier_id' => $supplierId, 'run_id' => $runId, 'external_sku' => mb_substr($offer['sku'], 0, 190, 'UTF-8'), 'name' => mb_substr($offer['name'], 0, 255, 'UTF-8'), 'action' => 'new', 'new_price_minor' => $newPrice, 'new_stock' => $newStock, 'supplier_price_minor' => (int) round($offer['price'] * 100), 'status' => 'pending', 'created_at' => $now]);
                    ++$stats['new'];
                }
                continue;
            }
            ++$stats['matched'];
            $priceDiff = (int) $supplier['apply_price'] === 1 && $variant['price_minor'] !== null && (int) $variant['price_minor'] !== $newPrice;
            $stockDiff = (int) $supplier['apply_stock'] === 1 && $newStock !== null && $variant['stock'] !== null && abs((float) $variant['stock'] - $newStock) > 0.0005;
            if (!$priceDiff && !$stockDiff) {
                continue;
            }
            if ($stats['changed'] >= self::MAX_CHANGES) {
                break;
            }
            ++$stats['changed'];
            $item = [
                'supplier_id' => $supplierId, 'run_id' => $runId, 'external_sku' => mb_substr($offer['sku'], 0, 190, 'UTF-8'), 'name' => mb_substr($offer['name'], 0, 255, 'UTF-8'),
                'variant_id' => (int) $variant['variant_id'], 'product_name' => mb_substr((string) $variant['name'], 0, 255, 'UTF-8'),
                'action' => $priceDiff && $stockDiff ? 'both' : ($priceDiff ? 'price' : 'stock'),
                'old_price_minor' => $variant['price_minor'] !== null ? (int) $variant['price_minor'] : null, 'new_price_minor' => $priceDiff ? $newPrice : null,
                'old_stock' => $variant['stock'], 'new_stock' => $stockDiff ? $newStock : null,
                'supplier_price_minor' => (int) round($offer['price'] * 100), 'status' => 'pending', 'created_at' => $now,
            ];
            $this->db->insert('mc_supplier_item', $item);
            if ($auto) {
                $id = (int) $this->db->lastInsertId();
                if ($this->applyOne($storeId, $marketId, $item + ['id' => $id], 'both')) {
                    ++$stats['applied'];
                }
            }
        }

        return $stats;
    }

    public function price(float $supplierPrice, float $markupPercent, string $rounding): int
    {
        $price = $supplierPrice * (1 + $markupPercent / 100);
        $minor = match ($rounding) {
            'whole' => (int) (round($price) * 100),
            'tens' => (int) (round($price / 10) * 1000),
            default => (int) round($price * 100),
        };

        return max(0, $minor);
    }

    /** @param array<string,mixed> $supplier */
    private function download(array $supplier, string $target): void
    {
        $options = ['timeout' => 30, 'max_duration' => 300, 'headers' => ['Accept' => 'application/xml,text/xml,text/csv,*/*', 'User-Agent' => 'NexoraSupplierSync/1.0']];
        if (!empty($supplier['http_user']) && !empty($supplier['http_pass_enc'])) {
            $options['auth_basic'] = [(string) $supplier['http_user'], $this->vault->decrypt((string) $supplier['http_pass_enc'], self::CTX)];
        }
        if (!$this->safeUrl((string) $supplier['feed_url'])) {
            throw new \DomainException(CanonicalUiText::get('supplier.runtime.invalid'));
        }
        $response = $this->http->request('GET', (string) $supplier['feed_url'], $options);
        if ($response->getStatusCode() >= 400) {
            throw new \DomainException(CanonicalUiText::get('supplier.runtime.http', ['code' => (string) $response->getStatusCode()]));
        }
        $out = fopen($target, 'wb');
        if ($out === false) {
            throw new \DomainException(CanonicalUiText::get('supplier.runtime.failed'));
        }
        $bytes = 0;
        try {
            foreach ($this->http->stream($response) as $chunk) {
                $data = $chunk->getContent();
                $bytes += strlen($data);
                if ($bytes > self::MAX_FEED_BYTES) {
                    throw new \DomainException(CanonicalUiText::get('supplier.runtime.too_big'));
                }
                fwrite($out, $data);
            }
        } finally {
            fclose($out);
        }
        if ($bytes === 0) {
            throw new \DomainException(CanonicalUiText::get('supplier.runtime.empty'));
        }
    }

    private function safeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            return false;
        }
        if ($this->allowPrivateHosts) {
            return true;
        }
        $host = strtolower((string) $parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP) === false || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** @param array{rows:int,matched:int,changed:int,applied:int,new:int} $stats */
    private function finish(int $supplierId, int $runId, string $status, string $message, array $stats): void
    {
        $now = $this->now();
        $this->db->update('mc_supplier_run', ['finished_at' => $now, 'rows_total' => $stats['rows'], 'matched' => $stats['matched'], 'changed' => $stats['changed'], 'applied' => $stats['applied'], 'new_total' => $stats['new'], 'status' => $status, 'message' => $message !== '' ? $message : null], ['id' => $runId]);
        $this->db->update('mc_supplier', ['last_run_at' => $now, 'last_status' => $status, 'last_message' => $message !== '' ? $message : null], ['id' => $supplierId]);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
