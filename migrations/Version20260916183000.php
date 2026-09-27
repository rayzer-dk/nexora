<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260916183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add measurement units, content/reviews, faceted SEO landing registry and update checkpoints.';
    }

    public function up(Schema $schema): void
    {
        if (!($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform)) {
            throw new RuntimeException('Nexora Commerce supports MySQL/MariaDB for this migration.');
        }

        $path = dirname(__DIR__) . '/resources/database/mysql/005_measurement_content_indexing.sql';
        $sql = (string) file_get_contents($path);
        $sql = preg_replace('/^--.*$/m', '', $sql) ?? $sql;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $this->addSql($statement);
            }
        }
    }

    public function down(Schema $schema): void
    {
        throw new RuntimeException('Schema v5 is forward-only. Use the update checkpoint to roll back safely.');
    }
}
