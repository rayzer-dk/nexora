<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261205110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'E-mail templates and lifecycle e-mails can be written as HTML.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_notification_template ADD is_html TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE mc_marketing_automation ADD is_html TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_notification_template DROP is_html');
        $this->addSql('ALTER TABLE mc_marketing_automation DROP is_html');
    }
}
