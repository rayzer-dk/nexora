<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Application;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

/**
 * Materialises prices for currencies with auto_convert=1 from the store's base-currency prices.
 *
 * Converted rows are ordinary mc_price rows (source='fx', lowest priority), so every existing
 * price lookup - catalog, product page, cart, checkout, feeds, API - sees them without changes,
 * and an explicit merchant price in that currency always wins. Orders snapshot the amount, so a
 * later rate change never alters a placed order. Without a valid (non-expired) rate the
 * converted rows are removed and the currency simply stops being offered.
 */
final class CurrencyPriceSynchronizer
{
    public const SOURCE = 'fx';
    private const PRIORITY = 65000;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<array{store_id:int,currency:string,rate:?string,written:int,removed:int,mode:string}> */
    public function sync(?int $storeId = null, bool $full = false): array
    {
        $report = [];
        $stores = $this->db->fetchAllAssociative(
            "SELECT id,default_currency FROM mc_store WHERE status='active'" . ($storeId !== null ? ' AND id=' . (int) $storeId : ''),
        );
        foreach ($stores as $store) {
            $base = strtoupper((string) $store['default_currency']);
            $targets = $this->db->fetchAllAssociative(
                'SELECT sc.currency_code,sc.rounding_increment_minor,sc.rate_source,c.minor_units FROM mc_store_currency sc JOIN mc_currency c ON c.code=sc.currency_code WHERE sc.store_id=? AND sc.enabled=1 AND sc.auto_convert=1 AND sc.currency_code<>?',
                [(int) $store['id'], $base],
            );
            $baseUnits = (int) ($this->db->fetchOne('SELECT minor_units FROM mc_currency WHERE code=?', [$base]) ?: 2);
            foreach ($targets as $target) {
                $report[] = $this->syncCurrency((int) $store['id'], $base, $baseUnits, $target, $full);
            }
            // Currencies that are no longer auto-converted must not keep stale converted prices.
            $removed = $this->db->executeStatement(
                "DELETE p FROM mc_price p LEFT JOIN mc_store_currency sc ON sc.store_id=p.store_id AND sc.currency_code=p.currency AND sc.enabled=1 AND sc.auto_convert=1
                 WHERE p.store_id=? AND p.source=? AND (sc.currency_code IS NULL OR p.currency=?)",
                [(int) $store['id'], self::SOURCE, $base],
            );
            if ($removed > 0) {
                $report[] = ['store_id' => (int) $store['id'], 'currency' => '*', 'rate' => null, 'written' => 0, 'removed' => $removed, 'mode' => 'cleanup'];
            }
        }

        return $report;
    }

    /** Latest valid rate base->quote as a decimal string, using the inverse pair when needed. */
    public function rate(string $base, string $quote, string $source = 'nbu'): ?string
    {
        // A manual rate is used only when the store chose "manual"; otherwise fetched rates are used so that a
        // forgotten manual value can never silently override the official one (and vice versa).
        $providerFilter = $source === 'manual' ? "provider='manual'" : "provider<>'manual'";
        $row = $this->db->fetchAssociative(
            'SELECT base_currency,rate FROM mc_exchange_rate WHERE ((base_currency=? AND quote_currency=?) OR (base_currency=? AND quote_currency=?))
             AND ' . $providerFilter . ' AND rate>0 AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP(6)) ORDER BY observed_at DESC,id DESC LIMIT 1',
            [$base, $quote, $quote, $base],
        );
        if (!is_array($row)) {
            return null;
        }
        $rate = (string) $row['rate'];

        return strtoupper((string) $row['base_currency']) === $base ? $rate : sprintf('%.12F', 1 / (float) $rate);
    }

    /** @param array{currency_code:string,rounding_increment_minor:int|string,rate_source?:string,minor_units:int|string} $target */
    private function syncCurrency(int $storeId, string $base, int $baseUnits, array $target, bool $full): array
    {
        $currency = strtoupper((string) $target['currency_code']);
        $rate = $this->rate($base, $currency, (string) ($target['rate_source'] ?? 'nbu'));
        if ($rate === null) {
            $removed = $this->db->executeStatement('DELETE FROM mc_price WHERE store_id=? AND source=? AND currency=?', [$storeId, self::SOURCE, $currency]);

            return ['store_id' => $storeId, 'currency' => $currency, 'rate' => null, 'written' => 0, 'removed' => $removed, 'mode' => 'no_rate'];
        }

        $state = $this->db->fetchAssociative('SELECT rate_used,synced_at FROM mc_price_fx_state WHERE store_id=? AND currency=?', [$storeId, $currency]);
        $lastSync = is_array($state) ? (string) $state['synced_at'] : null;
        $sameRate = is_array($state) && abs((float) $state['rate_used'] - (float) $rate) < 1e-12;
        $mode = ($full || $lastSync === null || !$sameRate) ? 'full' : 'incremental';

        $sql = "SELECT p.variant_id,p.market_id,p.price_list_id,p.customer_group,p.min_quantity,p.max_quantity,p.amount_minor,p.compare_at_minor,p.tax_included,p.starts_at,p.ends_at
                FROM mc_price p WHERE p.store_id=? AND p.currency=? AND p.source='manual'";
        $params = [$storeId, $base];
        if ($mode === 'incremental') {
            $sql .= ' AND p.variant_id IN (SELECT DISTINCT variant_id FROM mc_price WHERE store_id=? AND currency=? AND source=\'manual\' AND updated_at>?)';
            array_push($params, $storeId, $base, (string) $lastSync);
        }
        $rows = $this->db->fetchAllAssociative($sql . ' ORDER BY p.variant_id', $params);

        $targetUnits = (int) $target['minor_units'];
        $increment = max(1, (int) $target['rounding_increment_minor']);
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $written = 0;
        $removed = 0;
        $this->db->transactional(function (Connection $db) use ($rows, $storeId, $currency, $rate, $baseUnits, $targetUnits, $increment, $now, $mode, &$written, &$removed): void {
            if ($mode === 'full') {
                $removed += $db->executeStatement('DELETE FROM mc_price WHERE store_id=? AND source=? AND currency=?', [$storeId, self::SOURCE, $currency]);
            } else {
                $variantIds = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['variant_id'], $rows)));
                foreach (array_chunk($variantIds, 500) as $chunk) {
                    $removed += $db->executeStatement(
                        'DELETE FROM mc_price WHERE store_id=? AND source=? AND currency=? AND variant_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                        [$storeId, self::SOURCE, $currency, ...$chunk],
                    );
                }
                // Base price deleted -> converted price must go too.
                $removed += $db->executeStatement(
                    "DELETE fx FROM mc_price fx LEFT JOIN mc_price b ON b.store_id=fx.store_id AND b.variant_id=fx.variant_id AND b.source='manual' AND b.currency<>fx.currency
                     WHERE fx.store_id=? AND fx.source=? AND fx.currency=? AND b.id IS NULL",
                    [$storeId, self::SOURCE, $currency],
                );
            }
            foreach ($rows as $row) {
                if ($this->hasExplicit($db, $storeId, $currency, $row)) {
                    continue;
                }
                $db->insert('mc_price', [
                    'variant_id' => (int) $row['variant_id'],
                    'store_id' => $storeId,
                    'price_list_id' => $row['price_list_id'],
                    'market_id' => $row['market_id'],
                    'currency' => $currency,
                    'customer_group' => (string) $row['customer_group'],
                    'min_quantity' => (string) $row['min_quantity'],
                    'max_quantity' => $row['max_quantity'],
                    'amount_minor' => $this->convert((int) $row['amount_minor'], $rate, $baseUnits, $targetUnits, $increment),
                    'compare_at_minor' => $row['compare_at_minor'] === null ? null : $this->convert((int) $row['compare_at_minor'], $rate, $baseUnits, $targetUnits, $increment),
                    'tax_included' => (int) $row['tax_included'],
                    'priority' => self::PRIORITY,
                    'source' => self::SOURCE,
                    'starts_at' => $row['starts_at'],
                    'ends_at' => $row['ends_at'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                ++$written;
            }
            $db->executeStatement(
                'INSERT INTO mc_price_fx_state (store_id,currency,rate_used,synced_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE rate_used=VALUES(rate_used),synced_at=VALUES(synced_at)',
                [$storeId, $currency, $rate, $now],
            );
        });

        return ['store_id' => $storeId, 'currency' => $currency, 'rate' => $rate, 'written' => $written, 'removed' => $removed, 'mode' => $mode];
    }

    /** @param array<string,mixed> $row */
    private function hasExplicit(Connection $db, int $storeId, string $currency, array $row): bool
    {
        return (int) $db->fetchOne(
            "SELECT COUNT(*) FROM mc_price WHERE store_id=? AND variant_id=? AND currency=? AND source='manual' AND customer_group=? AND min_quantity=? AND market_id <=> ? AND price_list_id <=> ?",
            [$storeId, (int) $row['variant_id'], $currency, (string) $row['customer_group'], (string) $row['min_quantity'], $row['market_id'], $row['price_list_id']],
        ) > 0;
    }

    private function convert(int $amountMinor, string $rate, int $baseUnits, int $targetUnits, int $increment): int
    {
        // amount (base minor units) * rate, expressed in target minor units, rounded half-up to the increment.
        // Doubles keep 15+ significant digits, far beyond any realistic price * rate product.
        $minor = $amountMinor * (float) $rate * (10 ** ($targetUnits - $baseUnits));

        return max(0, (int) round($minor / $increment, 0, PHP_ROUND_HALF_UP) * $increment);
    }
}
