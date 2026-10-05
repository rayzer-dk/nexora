<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261217100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Back-in-stock requests: when the shop owner saw a new one.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_stock_notification_request ADD admin_seen_at DATETIME(6) NULL');
        $this->addSql('CREATE INDEX idx_stock_request_seen ON mc_stock_notification_request (store_id, status, admin_seen_at)');
        $this->addSql('CREATE INDEX idx_stock_request_created ON mc_stock_notification_request (store_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_stock_request_created ON mc_stock_notification_request');
        $this->addSql('DROP INDEX idx_stock_request_seen ON mc_stock_notification_request');
        $this->addSql('ALTER TABLE mc_stock_notification_request DROP COLUMN admin_seen_at');
    }
}
