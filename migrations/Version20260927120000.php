<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927120000 extends AbstractMigration
{
    private const TARGET = 'utf8mb4_unicode_ci';

    public function getDescription(): string
    {
        return 'Unify utf8mb4 collation across all Nexora tables and the database default.';
    }

    public function up(Schema $schema): void
    {
        $database = (string) $this->connection->fetchOne('SELECT DATABASE()');
        $this->addSql(sprintf('ALTER DATABASE %s CHARACTER SET utf8mb4 COLLATE %s', $this->quote($database), self::TARGET));

        $tables = $this->connection->fetchFirstColumn(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
               AND table_name LIKE 'mc\\_%' AND table_collation <> ?
             ORDER BY table_name",
            [self::TARGET],
        );
        if ($tables === []) {
            return;
        }

        $foreignKeys = $this->characterForeignKeys();
        foreach ($foreignKeys as $fk) {
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $this->quote($fk['table']), $this->quote($fk['name'])));
        }
        foreach ($tables as $table) {
            $this->addSql(sprintf('ALTER TABLE %s CONVERT TO CHARACTER SET utf8mb4 COLLATE %s', $this->quote((string) $table), self::TARGET));
        }
        foreach ($foreignKeys as $fk) {
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE %s',
                $this->quote($fk['table']),
                $this->quote($fk['name']),
                implode(', ', array_map($this->quote(...), $fk['columns'])),
                $this->quote($fk['referenced_table']),
                implode(', ', array_map($this->quote(...), $fk['referenced_columns'])),
                $fk['on_delete'],
                $fk['on_update'],
            ));
        }
    }

    public function down(Schema $schema): void
    {
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function characterForeignKeys(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT k.table_name, k.constraint_name, k.column_name, k.referenced_table_name, k.referenced_column_name,
                    k.ordinal_position, r.delete_rule, r.update_rule, c.data_type
             FROM information_schema.key_column_usage k
             JOIN information_schema.referential_constraints r
               ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name AND r.table_name = k.table_name
             JOIN information_schema.columns c
               ON c.table_schema = k.table_schema AND c.table_name = k.table_name AND c.column_name = k.column_name
             WHERE k.table_schema = DATABASE() AND k.referenced_table_name IS NOT NULL AND k.table_name LIKE 'mc\\_%'
             ORDER BY k.table_name, k.constraint_name, k.ordinal_position",
        );
        $grouped = [];
        $hasCharacterColumn = [];
        foreach ($rows as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $key = $row['table_name'] . '.' . $row['constraint_name'];
            $grouped[$key] ??= [
                'table' => (string) $row['table_name'],
                'name' => (string) $row['constraint_name'],
                'columns' => [],
                'referenced_table' => (string) $row['referenced_table_name'],
                'referenced_columns' => [],
                'on_delete' => $this->rule((string) $row['delete_rule']),
                'on_update' => $this->rule((string) $row['update_rule']),
            ];
            $grouped[$key]['columns'][] = (string) $row['column_name'];
            $grouped[$key]['referenced_columns'][] = (string) $row['referenced_column_name'];
            if (in_array(strtolower((string) $row['data_type']), ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'enum', 'set'], true)) {
                $hasCharacterColumn[$key] = true;
            }
        }

        return array_values(array_intersect_key($grouped, $hasCharacterColumn));
    }

    private function rule(string $rule): string
    {
        $rule = strtoupper(trim($rule));
        return in_array($rule, ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION', 'SET DEFAULT'], true) ? $rule : 'RESTRICT';
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
