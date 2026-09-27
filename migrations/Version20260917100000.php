<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260917100000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add checkout idempotency to immutable sales orders.'; }
    public function up(Schema $schema): void
    {
        if (!($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform)) throw new RuntimeException('MySQL/MariaDB required.');
        $sql=(string)file_get_contents(dirname(__DIR__).'/resources/database/mysql/012_checkout_order_runtime.sql');
        foreach (preg_split('/;\s*(?:\r?\n|$)/',$sql) ?: [] as $statement) { $statement=trim($statement); if($statement!=='') $this->addSql($statement); }
    }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE mc_sales_order DROP INDEX uq_sales_order_checkout_idempotency, DROP COLUMN checkout_idempotency_key'); }
}
