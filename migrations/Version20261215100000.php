<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261215100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Own redirects, the 404 log and the link check use the same collation as every other table.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        foreach (['mc_custom_redirect', 'mc_not_found_log', 'mc_link_check_issue'] as $table) {
            $this->addSql("ALTER TABLE $table CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
    }

    public function down(Schema $schema): void
    {
    }
}
