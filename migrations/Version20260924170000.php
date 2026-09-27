<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Harden integration queue crash recovery with explicit worker lease fields.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_integration_sync_queue ADD COLUMN locked_at DATETIME(6) NULL AFTER available_at, ADD COLUMN locked_by VARCHAR(64) NULL AFTER locked_at');
        $this->addSql('ALTER TABLE mc_integration_sync_queue ADD KEY idx_integration_sync_lease (status, locked_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_integration_sync_queue DROP INDEX idx_integration_sync_lease, DROP COLUMN locked_by, DROP COLUMN locked_at');
    }
}
