<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Admin interface language per administrator, independent of storefront and content locales. */
final class Version20260927191000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Per-administrator interface language.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_admin_user ADD ui_locale VARCHAR(16) NULL AFTER display_name');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_admin_user DROP COLUMN ui_locale');
    }
}
