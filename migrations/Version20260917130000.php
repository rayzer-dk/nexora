<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260917130000 extends AbstractMigration
{
    public function getDescription(): string { return 'Online payment lifecycle, provider ordering metadata and refund ledger.'; }
    public function up(Schema $schema): void
    {
        if (!($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform)) throw new RuntimeException('MySQL/MariaDB required.');
        $sql=(string)file_get_contents(dirname(__DIR__).'/resources/database/mysql/013_payment_lifecycle.sql');
        $sql=preg_replace('/^--.*$/m','',$sql) ?? $sql;
        foreach (preg_split('/;\s*(?:\r?\n|$)/',$sql) ?: [] as $statement) { $statement=trim($statement); if($statement!=='') $this->addSql($statement); }
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_payment_refund');
        $this->addSql('ALTER TABLE mc_payment DROP INDEX idx_payment_provider_modified, DROP COLUMN provider_payload, DROP COLUMN failure_reason, DROP COLUMN refunded_minor, DROP COLUMN cancelled_at, DROP COLUMN failed_at, DROP COLUMN paid_at, DROP COLUMN provider_modified_at');
    }
}
