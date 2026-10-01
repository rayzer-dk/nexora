<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

use Doctrine\DBAL\Connection;

/**
 * Finds stored pictures that nothing uses any more and moves their files to a trash folder (never an outright delete).
 *
 * A picture counts as used when ANY of these is true, so a photo that is in use somewhere is never touched:
 *  - a row of any table points at it by id (product photos and documents, category images, page share images, the
 *    library membership, and every other foreign key to the asset table, found from the database itself);
 *  - its file stem appears in rich text or settings (descriptions, pages, blog, layouts, configuration revisions);
 *  - it is younger than the grace period, or a demo picture.
 * Trashed files stay in var/media-trash for the retention period and can be copied back by hand before they are purged.
 */
final class MediaOrphanService
{
    /** Tables that are huge, written constantly and never hold picture references. */
    private const SKIP_TABLE = '~(log|audit|outbox|queue|session|history|analytics|traffic|attempt|token|cache|lock|metric|idempotency|webhook_delivery|notification_message)~i';
    /** Explicit id references that exist today; any other foreign key to the asset table is discovered at run time. */
    private const KNOWN_REFERENCES = [
        ['mc_product_media', 'media_asset_id'],
        ['mc_product_document', 'media_id'],
        ['mc_category_image', 'asset_id'],
        ['mc_content_page_meta', 'og_asset_id'],
        ['mc_store_media_asset', 'asset_id'],
    ];
    /** Tables that are revision logs but DO hold picture references (layout and settings payloads). */
    private const ALWAYS_SCAN = [['mc_configuration_revision', 'payload'], ['mc_layout_revision', 'payload']];

    /** @var list<array{0:string,1:string}>|null */
    private ?array $idReferences = null;
    /** @var list<array{0:string,1:string}>|null */
    private ?array $textColumns = null;

    public function __construct(private readonly Connection $connection, private readonly string $projectDir)
    {
    }

    /**
     * Pictures nothing uses. Nothing is changed.
     *
     * @return list<array{id:int,key:string}>
     */
    public function find(int $limit = 200, int $graceDays = 30): array
    {
        $limit = max(1, min(2000, $limit));
        $where = ["JSON_EXTRACT(COALESCE(ma.metadata, JSON_OBJECT()), '$.demo') IS NULL", 'ma.created_at < ?'];
        foreach ($this->idReferences() as [$table, $column]) {
            $where[] = "NOT EXISTS (SELECT 1 FROM `{$table}` r WHERE r.`{$column}`=ma.id)";
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ma.id,ma.storage_key FROM mc_media_asset ma WHERE ' . implode(' AND ', $where) . " ORDER BY ma.id LIMIT {$limit}",
            [gmdate('Y-m-d H:i:s', time() - max(0, $graceDays) * 86400)],
        );
        $found = [];
        foreach ($rows as $row) {
            $key = (string) $row['storage_key'];
            if ($key !== '' && !$this->mentionedInText($key)) {
                $found[] = ['id' => (int) $row['id'], 'key' => $key];
            }
        }

        return $found;
    }

    /**
     * Moves the files of every unused picture to var/media-trash and removes its record.
     *
     * @return array{assets:int,files:int}
     */
    public function trash(bool $dryRun = true, int $limit = 200, int $graceDays = 30): array
    {
        $result = ['assets' => 0, 'files' => 0];
        $trashRoot = rtrim($this->projectDir, '/\\') . '/var/media-trash/' . gmdate('Ymd');
        foreach ($this->find($limit, $graceDays) as $asset) {
            ++$result['assets'];
            $stem = $this->stemOf($asset['key']);
            $files = $this->filesOf($stem);
            $result['files'] += count($files);
            if ($dryRun) {
                continue;
            }
            foreach ($files as $relative) {
                $from = $this->mediaRoot() . '/' . $relative;
                $to = $trashRoot . '/' . $relative;
                if (!is_dir(dirname($to)) && !@mkdir(dirname($to), 0755, true) && !is_dir(dirname($to))) {
                    continue 2; // the trash is not writable: keep the picture instead of losing it
                }
                @rename($from, $to);
            }
            $this->connection->delete('mc_media_asset', ['id' => $asset['id']]);
        }

        return $result;
    }

    /** Removes trash folders older than the retention period. @return int folders removed */
    public function purgeTrash(int $retentionDays = 30): int
    {
        $root = rtrim($this->projectDir, '/\\') . '/var/media-trash';
        if (!is_dir($root)) {
            return 0;
        }
        $removed = 0;
        $limit = gmdate('Ymd', time() - max(1, $retentionDays) * 86400);
        foreach (scandir($root) ?: [] as $entry) {
            if (preg_match('~^\d{8}$~', $entry) === 1 && $entry < $limit) {
                $this->removeTree($root . '/' . $entry);
                ++$removed;
            }
        }

        return $removed;
    }

    /** @return list<array{0:string,1:string}> */
    private function idReferences(): array
    {
        if ($this->idReferences !== null) {
            return $this->idReferences;
        }
        $pairs = [];
        foreach (self::KNOWN_REFERENCES as [$table, $column]) {
            if ($this->columnExists($table, $column)) {
                $pairs[$table . '.' . $column] = [$table, $column];
            }
        }
        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='mc_media_asset' AND REFERENCED_COLUMN_NAME='id'",
            );
            foreach ($rows as $row) {
                $pairs[$row['t'] . '.' . $row['c']] = [(string) $row['t'], (string) $row['c']];
            }
        } catch (\Throwable) {
        }

        return $this->idReferences = array_values($pairs);
    }

    /** @return list<array{0:string,1:string}> */
    private function textColumns(): array
    {
        if ($this->textColumns !== null) {
            return $this->textColumns;
        }
        $pairs = [];
        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'mc\\_%' AND (DATA_TYPE IN ('text','mediumtext','longtext','json') OR (DATA_TYPE='varchar' AND CHARACTER_MAXIMUM_LENGTH>=255))",
            );
            foreach ($rows as $row) {
                $table = (string) $row['t'];
                if (preg_match(self::SKIP_TABLE, $table) === 1 && !in_array([$table, (string) $row['c']], self::ALWAYS_SCAN, true)) {
                    continue;
                }
                $pairs[$table . '.' . $row['c']] = [$table, (string) $row['c']];
            }
        } catch (\Throwable) {
        }
        foreach (self::ALWAYS_SCAN as [$table, $column]) {
            if ($this->columnExists($table, $column)) {
                $pairs[$table . '.' . $column] = [$table, $column];
            }
        }

        return $this->textColumns = array_values($pairs);
    }

    private function mentionedInText(string $key): bool
    {
        $needle = '%' . addcslashes($this->stemOf($key), '%_\\') . '%';
        foreach ($this->textColumns() as [$table, $column]) {
            if ($table === 'mc_media_asset') {
                continue; // the asset's own record names its file
            }
            try {
                if ($this->connection->fetchOne("SELECT 1 FROM `{$table}` WHERE CAST(`{$column}` AS CHAR) LIKE ? LIMIT 1", [$needle]) !== false) {
                    return true;
                }
            } catch (\Throwable) {
                return true; // a column that cannot be checked counts as "in use": losing a picture is worse than keeping one
            }
        }

        return false;
    }

    /** media/<stem>.<ext> => <stem> (the part shared by the master, the source and every variant) */
    private function stemOf(string $key): string
    {
        $key = ltrim(str_replace('\\', '/', $key), '/');
        $base = basename($key);
        $dot = strpos($base, '.');

        return ($dot === false ? $key : substr($key, 0, strlen($key) - strlen($base)) . substr($base, 0, $dot));
    }

    /** @return list<string> files (relative to media/) that belong to the stem */
    private function filesOf(string $stem): array
    {
        if ($stem === '' || str_contains($stem, '..')) {
            return [];
        }
        $dir = dirname($this->mediaRoot() . '/' . $stem);
        $prefix = basename($stem) . '.';
        $files = [];
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
            if (str_starts_with($name, $prefix) && is_file($dir . '/' . $name)) {
                $files[] = ltrim(substr($dir . '/' . $name, strlen($this->mediaRoot())), '/');
            }
        }

        return $files;
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $column]) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function mediaRoot(): string
    {
        return rtrim($this->projectDir, '/\\') . '/public/media';
    }

    private function removeTree(string $path): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
