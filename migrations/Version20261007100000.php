<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Form builder: per-language texts (translations) for forms.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_form ADD translations JSON NULL AFTER fields');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_form DROP COLUMN translations');
    }
}
