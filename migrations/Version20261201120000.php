<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261201120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Campaigns can carry the shop\'s own HTML (inside the branded template or as a full custom layout).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mc_marketing_campaign ADD COLUMN body_format VARCHAR(12) NOT NULL DEFAULT 'text' AFTER body_text");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_marketing_campaign DROP COLUMN body_format');
    }
}
