<?php

declare(strict_types=1);

namespace Commerce\Core\Recovery;

use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Dependency-light recovery engine. This file can be required directly by bin/recovery.php
 * even when Composer/Symfony is unavailable after a failed update.
 */
final class RecoveryArchiveRestorer
{
    /** @var list<string> */
    private array $swappedRoots = [];

    /** @var array<string,string|null> */
    private array $swappedFiles = [];

    public function __construct(private readonly string $projectDir)
    {
    }

    /** @return array<string,mixed> */
    public function inspect(string $archivePath): array
    {
        if (!is_file($archivePath)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.844e1203aea2'));
        }
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.feb8ef54ed85'));
        }
        try {
            $this->validateArchiveNames($zip);
            $raw = $zip->getFromName('snapshot.json');
            if (!is_string($raw) || $raw === '') {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.e44bc2d83f07'));
            }
            $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || (int) ($manifest['format'] ?? 0) !== 1) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.1abf8ebc9024'));
            }
            $schemaRaw = $zip->getFromName('database/schema.json');
            if (!is_string($schemaRaw) || $schemaRaw === '') {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.4cab9113ccde'));
            }
            $schema = json_decode($schemaRaw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($schema) || (int) ($schema['format'] ?? 0) !== 1 || !is_array($schema['tables'] ?? null)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.243c2a1a9c6b'));
            }
            foreach ($schema['tables'] as $table) {
                if (!is_array($table)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.150e454a92e4'));
                }
                $name = (string) ($table['name'] ?? '');
                $dataFile = (string) ($table['data_file'] ?? '');
                if (!$this->identifier($name) || preg_match('#^data/[A-Za-z0-9_]+\.jsonl$#D', $dataFile) !== 1) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d9e3036cc13c'));
                }
                $entry = 'database/' . $dataFile;
                $stream = $zip->getStream($entry);
                if (!is_resource($stream)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5b1b9aaa1963') . $name . '.');
                }
                $hash = hash_init('sha256');
                $rowsSeen = 0;
                while (($line = fgets($stream)) !== false) {
                    hash_update($hash, $line);
                    if (trim($line) === '') {
                        continue;
                    }
                    $row = json_decode($line, true, 256, JSON_THROW_ON_ERROR);
                    if (!is_array($row)) {
                        throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.aeed9883f31b') . $name . '.');
                    }
                    foreach ((array) ($table['columns'] ?? []) as $columnRaw) {
                        $column = (string) $columnRaw;
                        if (!$this->identifier($column) || !array_key_exists($column, $row)) {
                            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9477bdface9f') . $name . '.');
                        }
                        $encoded = $row[$column];
                        if ($encoded !== null && (!is_string($encoded) || base64_decode($encoded, true) === false)) {
                            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.c3960c3b92fc') . $name . '.');
                        }
                    }
                    $rowsSeen++;
                }
                fclose($stream);
                $actual = hash_final($hash);
                $expected = strtolower((string) ($table['sha256'] ?? ''));
                if ($expected === '' || !hash_equals($expected, strtolower($actual))) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.549aefc18ca8') . $name . '.');
                }
                $expectedRows = (int) ($table['row_count'] ?? -1);
                if ($expectedRows < 0 || $expectedRows !== $rowsSeen) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.1a47ba6e8f1c') . $name . '.');
                }
            }
            return ['manifest' => $manifest, 'schema' => $schema, 'sha256' => hash_file('sha256', $archivePath), 'size_bytes' => (int) filesize($archivePath)];
        } finally {
            $zip->close();
        }
    }

    /** @return array{snapshot_key:string,files_restored:bool,database_restored:bool} */
    public function restore(string $archivePath, ?PDO $pdo = null, bool $restoreFiles = true, bool $restoreDatabase = true): array
    {
        $this->swappedRoots = [];
        $this->swappedFiles = [];
        $inspection = $this->inspect($archivePath);
        $manifest = $inspection['manifest'];
        $snapshotKey = (string) ($manifest['snapshot_key'] ?? 'unknown');
        $maintenance = $this->projectPath('var/maintenance.flag');
        $this->writeMaintenanceFlag($maintenance, 'recovery:' . $snapshotKey);
        $stage = $this->projectPath('var/recovery/restore-stage/' . preg_replace('/[^A-Za-z0-9._-]/', '-', $snapshotKey));
        $quarantine = $this->projectPath('var/recovery/quarantine/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)));
        $this->removeTree($stage);
        $this->ensureDirectory($stage, 0750);
        $this->ensureDirectory($quarantine, 0750);

        try {
            if ($restoreFiles) {
                $this->extractFiles($archivePath, $stage);
                $this->validateStagedFiles($stage, $manifest);
            }
            if ($restoreDatabase) {
                $pdo ??= NativeDatabaseConnectionFactory::fromProject($this->projectDir);
                $this->restoreDatabase($archivePath, $inspection['schema'], $pdo);
            }
            if ($restoreFiles) {
                $this->activateStagedFiles($stage, $quarantine, $manifest);
            }
            if ($restoreDatabase && $pdo instanceof PDO) {
                try {
                    $statement = $pdo->prepare("UPDATE mc_recovery_snapshot SET status='restored',restored_at=NOW(6),last_error=NULL WHERE snapshot_key=?");
                    $statement->execute([$snapshotKey]);
                } catch (Throwable) {
                    // Metadata update is non-critical after the actual restore succeeded.
                }
            }
            @unlink($maintenance);
            return ['snapshot_key' => $snapshotKey, 'files_restored' => $restoreFiles, 'database_restored' => $restoreDatabase];
        } catch (Throwable $e) {
            // Keep maintenance enabled after a failed recovery. Serving a half-restored live system is unsafe.
            $this->rollbackSwappedRoots($quarantine);
            throw $e;
        } finally {
            $this->removeTree($stage);
        }
    }

    private function extractFiles(string $archivePath, string $stage): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2de04e5f3905'));
        }
        try {
            $this->validateArchiveNames($zip);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (!str_starts_with($name, 'files/')) {
                    continue;
                }
                if (!$zip->extractTo($stage, $name)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5f90e7a9372f') . $name . '.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    /** @param array<string,mixed> $manifest */
    private function validateStagedFiles(string $stage, array $manifest): void
    {
        $filesRoot = $stage . '/files';
        if (!is_dir($filesRoot)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.4409d6f05180'));
        }
        foreach ((array) ($manifest['managed_roots'] ?? []) as $rootRaw) {
            $root = $this->safeRelativePath((string) $rootRaw);
            if (!is_dir($filesRoot . '/' . $root)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.1aef6caf0447') . $root . '.');
            }
        }
        foreach ((array) ($manifest['managed_files'] ?? []) as $fileRaw) {
            $file = $this->safeRelativePath((string) $fileRaw);
            if ($this->belongsToManagedRoot($file, (array) ($manifest['managed_roots'] ?? []))) {
                continue;
            }
            if (!is_file($filesRoot . '/' . $file)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.01effbb89d48') . $file . '.');
            }
        }
    }

    /** @param array<string,mixed> $manifest */
    private function activateStagedFiles(string $stage, string $quarantine, array $manifest): void
    {
        $filesRoot = $stage . '/files';
        $roots = array_map(fn (mixed $root): string => $this->safeRelativePath((string) $root), (array) ($manifest['managed_roots'] ?? []));
        usort($roots, static function (string $a, string $b): int {
            if ($a === 'bin') return 1;
            if ($b === 'bin') return -1;
            return substr_count($b, '/') <=> substr_count($a, '/');
        });

        foreach ($roots as $root) {
            $source = $filesRoot . '/' . $root;
            $target = $this->projectPath($root);
            $backup = $quarantine . '/' . $root;
            $this->ensureDirectory(dirname($target), 0750);
            $this->ensureDirectory(dirname($backup), 0750);
            if (file_exists($target) || is_link($target)) {
                if (!@rename($target, $backup)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.e31b2e23211c') . $root . '.');
                }
                $this->swappedRoots[] = $root;
            }
            if (!@rename($source, $target)) {
                if (file_exists($backup) || is_link($backup)) {
                    @rename($backup, $target);
                }
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.e8af7319387d') . $root . '.');
            }
        }

        foreach ((array) ($manifest['managed_files'] ?? []) as $fileRaw) {
            $file = $this->safeRelativePath((string) $fileRaw);
            if ($this->belongsToManagedRoot($file, $roots)) {
                continue;
            }
            $source = $filesRoot . '/' . $file;
            if (!is_file($source)) {
                continue;
            }
            $target = $this->projectPath($file);
            $this->ensureDirectory(dirname($target), 0750);
            $backup = $quarantine . '/managed-files/' . $file;
            $this->ensureDirectory(dirname($backup), 0750);
            if (is_file($target) || is_link($target)) {
                if (!@rename($target, $backup)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.39459c131427') . $file . '.');
                }
                $this->swappedFiles[$file] = $backup;
            } else {
                $this->swappedFiles[$file] = null;
            }
            $tmp = $target . '.recovery-' . bin2hex(random_bytes(4));
            if (!@copy($source, $tmp)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5a09820a9e0a') . $file . '.');
            }
            @chmod($tmp, (fileperms($source) ?: 0644) & 0777);
            if (!@rename($tmp, $target)) {
                @unlink($tmp);
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.aa6a3923c4c6') . $file . '.');
            }
        }
    }

    /** @param array<string,mixed> $schema */
    private function restoreDatabase(string $archivePath, array $schema, PDO $pdo): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a310bb2e0099'));
        }
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $pdo->exec('SET UNIQUE_CHECKS=0');
            $existing = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
            foreach ($existing as $row) {
                $name = (string) ($row[0] ?? '');
                $type = strtoupper((string) ($row[1] ?? 'BASE TABLE'));
                if (!$this->identifier($name)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.da2298b96b9e'));
                }
                $quoted = '`' . str_replace('`', '``', $name) . '`';
                $pdo->exec(($type === 'VIEW' ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . $quoted);
            }

            foreach ($schema['tables'] as $table) {
                $name = (string) ($table['name'] ?? '');
                $createSql = (string) ($table['create_sql'] ?? '');
                if (!$this->identifier($name) || $createSql === '') {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.218c5da26a3c'));
                }
                $pdo->exec($createSql);
            }

            foreach ($schema['tables'] as $table) {
                $name = (string) $table['name'];
                $columns = array_values(array_map('strval', (array) ($table['columns'] ?? [])));
                foreach ($columns as $column) {
                    if (!$this->identifier($column)) {
                        throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.cff7aa1373f1'));
                    }
                }
                if ($columns === []) {
                    continue;
                }
                $quotedColumns = implode(',', array_map(static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`', $columns));
                $placeholders = implode(',', array_fill(0, count($columns), '?'));
                $statement = $pdo->prepare('INSERT INTO `' . str_replace('`', '``', $name) . '` (' . $quotedColumns . ') VALUES (' . $placeholders . ')');
                $entry = 'database/' . (string) $table['data_file'];
                $stream = $zip->getStream($entry);
                if (!is_resource($stream)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.baa8595c6d66') . $name . '.');
                }
                try {
                    while (($line = fgets($stream)) !== false) {
                        if (trim($line) === '') {
                            continue;
                        }
                        $row = json_decode($line, true, 256, JSON_THROW_ON_ERROR);
                        if (!is_array($row)) {
                            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5249dd68e633') . $name . '.');
                        }
                        $values = [];
                        foreach ($columns as $column) {
                            $encoded = $row[$column] ?? null;
                            if ($encoded === null) {
                                $values[] = null;
                                continue;
                            }
                            if (!is_string($encoded)) {
                                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.dff28c46ecd9') . $name . '.');
                            }
                            $decoded = base64_decode($encoded, true);
                            if ($decoded === false) {
                                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.625e0847becf') . $name . '.');
                            }
                            $values[] = $decoded;
                        }
                        $statement->execute($values);
                    }
                } finally {
                    fclose($stream);
                }
            }
            $pdo->exec('SET UNIQUE_CHECKS=1');
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable $e) {
            try { $pdo->exec('SET UNIQUE_CHECKS=1'); } catch (Throwable) {}
            try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable) {}
            throw $e;
        } finally {
            $zip->close();
        }
    }

    private function validateArchiveNames(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if ($name === '' || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:\//', $name) === 1 || str_contains('/' . $name . '/', '/../')) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.1e720854a0e0'));
            }
        }
    }

    private function rollbackSwappedRoots(string $quarantine): void
    {
        foreach (array_reverse(array_keys($this->swappedFiles)) as $file) {
            $target = $this->projectPath($file);
            $backup = $this->swappedFiles[$file];
            if (is_file($target) || is_link($target)) {
                @unlink($target);
            }
            if (is_string($backup) && (is_file($backup) || is_link($backup))) {
                $this->ensureDirectory(dirname($target), 0750);
                @rename($backup, $target);
            }
        }
        $this->swappedFiles = [];

        foreach (array_reverse($this->swappedRoots) as $root) {
            $target = $this->projectPath($root);
            $backup = $quarantine . '/' . $root;
            if (!file_exists($backup) && !is_link($backup)) {
                continue;
            }
            $failed = $quarantine . '/failed-restored/' . $root;
            $this->ensureDirectory(dirname($failed), 0750);
            if (file_exists($target) || is_link($target)) {
                @rename($target, $failed);
            }
            @rename($backup, $target);
        }
        $this->swappedRoots = [];
    }

    /** @param list<mixed> $roots */
    private function belongsToManagedRoot(string $file, array $roots): bool
    {
        foreach ($roots as $rootRaw) {
            $root = rtrim($this->safeRelativePath((string) $rootRaw), '/');
            if ($file === $root || str_starts_with($file, $root . '/')) {
                return true;
            }
        }
        return false;
    }

    private function safeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_contains('/' . $path . '/', '/../') || str_contains($path, "\0") || preg_match('/^[A-Za-z]:\//', $path) === 1) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.32ae321637d9'));
        }
        return $path;
    }

    private function identifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/D', $value) === 1;
    }

    private function writeMaintenanceFlag(string $path, string $reason): void
    {
        $this->ensureDirectory(dirname($path), 0750);
        $payload = json_encode(['enabled_at' => gmdate('c'), 'reason' => $reason, 'retry_after' => 30], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d220fce9911a'));
        }
        @chmod($path, 0640);
    }

    private function projectPath(string $relative): string
    {
        return rtrim($this->projectDir, '/\\') . '/' . ltrim($relative, '/\\');
    }

    private function ensureDirectory(string $directory, int $mode): void
    {
        if (!is_dir($directory) && !@mkdir($directory, $mode, true) && !is_dir($directory)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ef01022b86ca'));
        }
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname()); else @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
