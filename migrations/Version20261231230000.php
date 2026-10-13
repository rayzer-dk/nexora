<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261231230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Floating contact buttons: WhatsApp number; third-party chat: delay (seconds) before its script is loaded.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_contact_widget ADD whatsapp VARCHAR(32) NULL AFTER viber');
        $this->addSql('ALTER TABLE mc_chat_widget ADD load_delay SMALLINT UNSIGNED NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_chat_widget DROP COLUMN load_delay');
        $this->addSql('ALTER TABLE mc_contact_widget DROP COLUMN whatsapp');
    }
}
