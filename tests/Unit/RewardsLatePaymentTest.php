<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Rewards\Application\GiftCardService;
use Commerce\Modules\Rewards\Application\LoyaltyService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;

/**
 * A payment that arrives after the order was failed or cancelled must spend the gift card and the points
 * again, and a later refund must give them back once more. Runs on a MySQL/MariaDB DATABASE_URL with
 * session-only copies of the four tables (nothing permanent is written); otherwise it is skipped.
 */
final class RewardsLatePaymentTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $url = (string) ($_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '');
        if (!str_starts_with($url, 'mysql://')) {
            self::markTestSkipped('A MySQL/MariaDB DATABASE_URL is needed.');
        }
        try {
            $this->db = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql']))->parse($url));
            $this->db->fetchOne('SELECT 1');
        } catch (\Throwable) {
            self::markTestSkipped('The database is not reachable.');
        }
        $this->db->executeStatement('CREATE TEMPORARY TABLE mc_gift_card (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, balance_minor BIGINT NOT NULL, status VARCHAR(16) NOT NULL, updated_at DATETIME(6) NULL) ENGINE=InnoDB');
        $this->db->executeStatement('CREATE TEMPORARY TABLE mc_gift_card_transaction (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, gift_card_id BIGINT UNSIGNED NOT NULL, order_id BIGINT UNSIGNED NULL, tx_type VARCHAR(32) NOT NULL, amount_minor BIGINT NOT NULL, balance_after_minor BIGINT UNSIGNED NOT NULL, idempotency_key VARCHAR(190) NOT NULL, created_at DATETIME(6) NOT NULL, UNIQUE KEY uq_key (idempotency_key)) ENGINE=InnoDB');
        $this->db->executeStatement('CREATE TEMPORARY TABLE mc_loyalty_account (store_id BIGINT UNSIGNED NOT NULL, customer_id BIGINT UNSIGNED NOT NULL, points_balance BIGINT NOT NULL, lifetime_earned BIGINT NOT NULL DEFAULT 0, lifetime_spent BIGINT NOT NULL DEFAULT 0, updated_at DATETIME(6) NULL, PRIMARY KEY (store_id, customer_id)) ENGINE=InnoDB');
        $this->db->executeStatement('CREATE TEMPORARY TABLE mc_loyalty_transaction (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, store_id BIGINT UNSIGNED NOT NULL, customer_id BIGINT UNSIGNED NOT NULL, order_id BIGINT UNSIGNED NULL, tx_type VARCHAR(32) NOT NULL, points BIGINT NOT NULL, balance_after BIGINT UNSIGNED NOT NULL, idempotency_key VARCHAR(190) NOT NULL, created_at DATETIME(6) NOT NULL, UNIQUE KEY uq_key (idempotency_key)) ENGINE=InnoDB');
    }

    private function cardBalance(): int
    {
        return (int) $this->db->fetchOne('SELECT balance_minor FROM mc_gift_card WHERE id=1');
    }

    private function newCardSpent(int $orderId, int $spent): GiftCardService
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->insert('mc_gift_card', ['id' => 1, 'balance_minor' => 1000 - $spent, 'status' => 'active', 'updated_at' => $now]);
        $this->db->insert('mc_gift_card_transaction', ['gift_card_id' => 1, 'order_id' => $orderId, 'tx_type' => 'redeem', 'amount_minor' => -$spent, 'balance_after_minor' => 1000 - $spent, 'idempotency_key' => 'order:' . $orderId . ':redeem', 'created_at' => $now]);

        return new GiftCardService($this->db, new PublicIdFactory());
    }

    public function testGiftCardIsSpentAgainWhenThePaymentArrivesLate(): void
    {
        $cards = $this->newCardSpent(5, 400);

        $cards->restoreForOrder($this->db, 5, 'failure');
        self::assertSame(1000, $this->cardBalance(), 'a failed payment gives the card back');

        self::assertTrue($cards->reapplyForOrder($this->db, 5), 'the late payment spends it again');
        self::assertSame(600, $this->cardBalance());
        self::assertTrue($cards->reapplyForOrder($this->db, 5), 'repeating the notice changes nothing');
        self::assertSame(600, $this->cardBalance());

        $cards->restoreForOrder($this->db, 5, 'refund');
        self::assertSame(1000, $this->cardBalance(), 'the refund of the paid order gives it back once more');
        $cards->restoreForOrder($this->db, 5, 'refund');
        self::assertSame(1000, $this->cardBalance(), 'and only once');
    }

    public function testNothingIsReappliedForAnOrderThatWasNeverGivenBack(): void
    {
        $cards = $this->newCardSpent(6, 400);

        self::assertTrue($cards->reapplyForOrder($this->db, 6));
        self::assertSame(600, $this->cardBalance());
    }

    public function testShortfallIsReportedWhenTheCardWasSpentElsewhere(): void
    {
        $cards = $this->newCardSpent(7, 400);
        $cards->restoreForOrder($this->db, 7, 'expired');
        $this->db->update('mc_gift_card', ['balance_minor' => 100], ['id' => 1]);

        self::assertFalse($cards->reapplyForOrder($this->db, 7));
        self::assertSame(100, $this->cardBalance());
    }

    public function testPointsFollowTheSameCycle(): void
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->insert('mc_loyalty_account', ['store_id' => 1, 'customer_id' => 9, 'points_balance' => 50, 'lifetime_spent' => 30, 'updated_at' => $now]);
        $this->db->insert('mc_loyalty_transaction', ['store_id' => 1, 'customer_id' => 9, 'order_id' => 8, 'tx_type' => 'redeem', 'points' => -30, 'balance_after' => 50, 'idempotency_key' => 'order:8:redeem', 'created_at' => $now]);
        $loyalty = new LoyaltyService($this->db);

        $loyalty->restoreSpendForOrder($this->db, 8, 'failure');
        self::assertSame(80, (int) $this->db->fetchOne('SELECT points_balance FROM mc_loyalty_account WHERE customer_id=9'));

        self::assertTrue($loyalty->reapplySpendForOrder($this->db, 8));
        self::assertSame(50, (int) $this->db->fetchOne('SELECT points_balance FROM mc_loyalty_account WHERE customer_id=9'));
        self::assertSame(30, (int) $this->db->fetchOne('SELECT lifetime_spent FROM mc_loyalty_account WHERE customer_id=9'));

        $loyalty->restoreSpendForOrder($this->db, 8, 'refund');
        self::assertSame(80, (int) $this->db->fetchOne('SELECT points_balance FROM mc_loyalty_account WHERE customer_id=9'));

        $this->db->update('mc_loyalty_account', ['points_balance' => 10], ['customer_id' => 9]);
        self::assertFalse($loyalty->reapplySpendForOrder($this->db, 8), 'not enough points left');
    }
}
