<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20270101040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '404 log: last User-Agent and a bot flag per missing address.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf((int) $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mc_not_found_log' AND COLUMN_NAME = 'is_bot'") > 0, 'mc_not_found_log already has the bot flag.');
        $this->addSql('ALTER TABLE mc_not_found_log ADD last_user_agent VARCHAR(255) DEFAULT NULL AFTER referer, ADD is_bot TINYINT(1) NOT NULL DEFAULT 0 AFTER last_user_agent, ADD KEY idx_not_found_bot (is_bot)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_not_found_log DROP KEY idx_not_found_bot, DROP COLUMN is_bot, DROP COLUMN last_user_agent');
    }
}
