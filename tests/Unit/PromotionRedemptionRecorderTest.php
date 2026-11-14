<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Promotion\Application\PromotionRedemptionRecorder;
use Commerce\Modules\Promotion\Domain\PromotionResult;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class PromotionRedemptionRecorderTest extends TestCase
{
    private function connection(): Connection
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('PRAGMA foreign_keys = ON');
        $db->executeStatement('CREATE TABLE mc_promotion (id INTEGER PRIMARY KEY, usage_count INTEGER NOT NULL DEFAULT 0, updated_at TEXT)');
        $db->executeStatement('CREATE TABLE mc_order_promotion (order_id INTEGER NOT NULL, promotion_id INTEGER NOT NULL REFERENCES mc_promotion(id), name TEXT, coupon_code TEXT, discount_minor INTEGER, snapshot_json TEXT)');
        $db->executeStatement('CREATE TABLE mc_promotion_redemption (promotion_id INTEGER NOT NULL REFERENCES mc_promotion(id), order_id INTEGER NOT NULL, customer_id INTEGER, email_normalized TEXT, coupon_code TEXT, discount_minor INTEGER, created_at TEXT)');
        $db->insert('mc_promotion', ['id' => 7, 'usage_count' => 0]);

        return $db;
    }

    public function testCustomerGroupDiscountIsNotRecordedAsAPromotion(): void
    {
        $db = $this->connection();
        $result = new PromotionResult(10000, 1500, 8500, [
            ['id' => 0, 'name' => 'Wholesale', 'code' => null, 'discount_minor' => 1000],
            ['id' => 7, 'name' => 'Spring', 'code' => 'SPRING', 'discount_minor' => 500],
        ]);

        (new PromotionRedemptionRecorder($db))->record(42, $result, 3, ' Buyer@Example.com ');

        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM mc_order_promotion'));
        self::assertSame(7, (int) $db->fetchOne('SELECT promotion_id FROM mc_order_promotion WHERE order_id=42'));
        self::assertSame('buyer@example.com', $db->fetchOne('SELECT email_normalized FROM mc_promotion_redemption'));
        self::assertSame(1, (int) $db->fetchOne('SELECT usage_count FROM mc_promotion WHERE id=7'));
    }

    public function testGroupDiscountAloneRecordsNothing(): void
    {
        $db = $this->connection();
        $result = new PromotionResult(10000, 1000, 9000, [['id' => 0, 'name' => 'Wholesale', 'code' => null, 'discount_minor' => 1000]]);

        (new PromotionRedemptionRecorder($db))->record(42, $result, 3, null);

        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM mc_order_promotion'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM mc_promotion_redemption'));
    }
}
