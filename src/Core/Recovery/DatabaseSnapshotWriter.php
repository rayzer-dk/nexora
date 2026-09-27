<?php

declare(strict_types=1);

namespace Commerce\Core\Recovery;

use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class DatabaseSnapshotWriter
{
    /**
     * Writes a portable MySQL/MariaDB snapshot as schema JSON plus base64 JSONL rows.
     * Values are kept byte-for-byte so BINARY UUIDs and hashes survive round-trips.
     *
     * @return array{tables:int,rows:int,bytes:int}
     */
    public function write(Connection $connection, string $directory): array
    {
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.43495576a718'));
        }
        $dataDirectory = $directory . '/data';
        if (!is_dir($dataDirectory) && !@mkdir($dataDirectory, 0750, true) && !is_dir($dataDirectory)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9a5ac688d0a6'));
        }

        $tables = $connection->fetchFirstColumn("SHOW FULL TABLES WHERE Table_type='BASE TABLE'");
        sort($tables, SORT_STRING);
        $schema = [
            'format' => 1,
            'driver' => 'mysql',
            'created_at' => gmdate('c'),
            'tables' => [],
        ];
        $rowCount = 0;
        $bytes = 0;

        foreach ($tables as $tableRaw) {
            $table = (string) $tableRaw;
            if (!$this->validIdentifier($table)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.977ad879f5ce'));
            }
            $createRow = $connection->fetchAssociative('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`');
            if (!is_array($createRow)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.8cbbbfdf79e3') . $table . '.');
            }
            $createValues = array_values($createRow);
            $createSql = isset($createValues[1]) ? (string) $createValues[1] : '';
            if ($createSql === '') {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.340192d6fac3') . $table . '.');
            }

            $columns = [];
            foreach ($connection->fetchAllAssociative('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`') as $column) {
                $name = (string) ($column['Field'] ?? '');
                if ($name === '' || !$this->validIdentifier($name)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ffa5926b31da') . $table . '.');
                }
                $columns[] = $name;
            }

            $dataFile = 'data/' . $table . '.jsonl';
            $absoluteDataFile = $directory . '/' . $dataFile;
            $handle = @fopen($absoluteDataFile, 'wb');
            if (!is_resource($handle)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.fdb58799b5cd') . $table . '.');
            }
            $tableRows = 0;
            try {
                foreach ($connection->iterateAssociative('SELECT * FROM `' . str_replace('`', '``', $table) . '`') as $row) {
                    $encoded = [];
                    foreach ($columns as $column) {
                        $value = $row[$column] ?? null;
                        $encoded[$column] = $value === null ? null : base64_encode((string) $value);
                    }
                    $line = json_encode($encoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
                    if (@fwrite($handle, $line) !== strlen($line)) {
                        throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d1e266215aaa') . $table . '.');
                    }
                    $tableRows++;
                    $rowCount++;
                }
            } finally {
                fclose($handle);
            }
            $fileBytes = (int) (@filesize($absoluteDataFile) ?: 0);
            $bytes += $fileBytes;
            $schema['tables'][] = [
                'name' => $table,
                'create_sql' => $createSql,
                'columns' => $columns,
                'row_count' => $tableRows,
                'data_file' => $dataFile,
                'sha256' => hash_file('sha256', $absoluteDataFile),
            ];
        }

        $schemaJson = json_encode($schema, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        if (@file_put_contents($directory . '/schema.json', $schemaJson, LOCK_EX) === false) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.db12bbf56e6f'));
        }
        @chmod($directory . '/schema.json', 0640);
        $bytes += strlen($schemaJson);

        return ['tables' => count($tables), 'rows' => $rowCount, 'bytes' => $bytes];
    }

    private function validIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/D', $value) === 1;
    }
}
