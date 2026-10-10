<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261230100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forum: reason for a hidden message, topic header and slow mode.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_forum_post ADD COLUMN hidden_reason VARCHAR(500) NULL');
        $this->addSql('ALTER TABLE mc_forum_topic ADD COLUMN header_text TEXT NULL, ADD COLUMN slow_mode_seconds INT UNSIGNED NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_forum_topic DROP COLUMN slow_mode_seconds, DROP COLUMN header_text');
        $this->addSql('ALTER TABLE mc_forum_post DROP COLUMN hidden_reason');
    }
}
