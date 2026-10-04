<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261205100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Support chat: how many seconds after the page loads the chat script is fetched (page speed).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_support_chat_settings ADD load_delay_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 3');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_support_chat_settings DROP load_delay_seconds');
    }
}
