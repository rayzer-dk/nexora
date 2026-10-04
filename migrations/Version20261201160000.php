<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261201160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SMS: only ordinary SMS are sent, the Flash SMS option and its columns are removed.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_sms_settings DROP COLUMN flash_supported');
        $this->addSql('ALTER TABLE mc_sms_log DROP COLUMN flash');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_sms_settings ADD flash_supported TINYINT(1) NOT NULL DEFAULT 0 AFTER sender');
        $this->addSql('ALTER TABLE mc_sms_log ADD flash TINYINT(1) NOT NULL DEFAULT 0 AFTER segments');
    }
}
