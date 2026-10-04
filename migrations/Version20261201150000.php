<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261201150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SMS gateway type: generic JSON gateway or the built-in SMS-fly driver.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mc_sms_settings ADD driver VARCHAR(12) NOT NULL DEFAULT 'json' AFTER enabled");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_sms_settings DROP COLUMN driver');
    }
}
