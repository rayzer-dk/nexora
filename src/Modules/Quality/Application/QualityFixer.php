<?php

declare(strict_types=1);

namespace Commerce\Modules\Quality\Application;

use Commerce\Modules\Pricing\Application\CurrencyPriceSynchronizer;
use Commerce\Modules\Pricing\Application\ExchangeRateService;
use Doctrine\DBAL\Connection;

/**
 * One-click repairs for the checks that have a safe, reversible fix. Everything else stays a link to the page
 * where the merchant decides (legal texts, payment keys, stock).
 */
final class QualityFixer
{
    public const FIXABLE = ['outbox_failed', 'rates_fresh', 'product_meta', 'fraud_enabled'];

    public function __construct(
        private readonly Connection $db,
        private readonly ExchangeRateService $rates,
        private readonly CurrencyPriceSynchronizer $prices,
    ) {
    }

    /** @return int how many things were changed */
    public function fix(string $id, int $storeId): int
    {
        return match ($id) {
            'outbox_failed' => $this->requeueFailedNotifications(),
            'rates_fresh' => $this->refreshRates($storeId),
            'product_meta' => $this->fillMetaTitles($storeId),
            'fraud_enabled' => $this->enableFraud($storeId),
            default => throw new \InvalidArgumentException('quality_fix_unknown'),
        };
    }

    private function requeueFailedNotifications(): int
    {
        return (int) $this->db->executeStatement("UPDATE mc_notification_outbox SET status='pending', attempts=0, available_at=UTC_TIMESTAMP(6), last_error=NULL, locked_at=NULL, lock_token=NULL WHERE status='failed'");
    }

    private function refreshRates(int $storeId): int
    {
        $stored = 0;
        if ($this->rates->requiredPairs() !== []) {
            $stored = (int) $this->rates->refresh()['stored'];
        }
        $this->prices->sync($storeId, true);

        return $stored;
    }

    /** Empty SEO titles of active products get the product name: never overwrites a title someone wrote. */
    private function fillMetaTitles(int $storeId): int
    {
        $locale = (string) $this->db->fetchOne('SELECT default_locale FROM mc_store WHERE id=?', [$storeId]);

        return (int) $this->db->executeStatement(
            "UPDATE mc_product_translation t JOIN mc_product p ON p.id=t.product_id AND p.status='active'
             SET t.meta_title=LEFT(t.name,70) WHERE t.store_id=? AND t.locale=? AND (t.meta_title IS NULL OR t.meta_title='') AND t.name<>''",
            [$storeId, $locale],
        );
    }

    private function enableFraud(int $storeId): int
    {
        return (int) $this->db->executeStatement(
            'INSERT INTO mc_store_security_settings (store_id,fraud_enabled,updated_at) VALUES (?,1,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE fraud_enabled=1,updated_at=UTC_TIMESTAMP(6)',
            [$storeId],
        ) > 0 ? 1 : 0;
    }
}
