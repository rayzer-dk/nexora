<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261201110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'E-mail design: colours and footer text of the transactional and campaign e-mails.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mc_notification_channel_settings
            ADD COLUMN mail_header_bg VARCHAR(7) NOT NULL DEFAULT '',
            ADD COLUMN mail_header_text VARCHAR(7) NOT NULL DEFAULT '',
            ADD COLUMN mail_accent VARCHAR(7) NOT NULL DEFAULT '',
            ADD COLUMN mail_page_bg VARCHAR(7) NOT NULL DEFAULT '',
            ADD COLUMN mail_card_bg VARCHAR(7) NOT NULL DEFAULT '',
            ADD COLUMN mail_text VARCHAR(7) NOT NULL DEFAULT '',
            ADD COLUMN mail_footer VARCHAR(300) NOT NULL DEFAULT ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_notification_channel_settings DROP COLUMN mail_header_bg, DROP COLUMN mail_header_text, DROP COLUMN mail_accent, DROP COLUMN mail_page_bg, DROP COLUMN mail_card_bg, DROP COLUMN mail_text, DROP COLUMN mail_footer');
    }
}
