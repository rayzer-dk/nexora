<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20270101050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Builder presence table: same collation as every other table (it was created with the server default).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_layout_presence CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The collation change is kept: the previous collation was the server default.');
    }
}
