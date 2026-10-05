<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261213100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reviews and questions from visitors who are not signed in: contact e-mail and a hashed client mark for limits.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        foreach (['mc_product_review', 'mc_product_question'] as $table) {
            $this->addSql("ALTER TABLE $table ADD guest_email VARCHAR(190) NULL, ADD client_hash CHAR(40) NULL");
            $this->addSql("CREATE INDEX idx_{$table}_client ON $table (client_hash, created_at)");
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['mc_product_review', 'mc_product_question'] as $table) {
            $this->addSql("DROP INDEX idx_{$table}_client ON $table");
            $this->addSql("ALTER TABLE $table DROP COLUMN guest_email, DROP COLUMN client_hash");
        }
    }
}
