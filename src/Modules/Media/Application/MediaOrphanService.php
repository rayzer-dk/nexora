<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

use Doctrine\DBAL\Connection;

/**
 * Finds stored pictures that nothing uses any more and moves their files to a trash folder (never an outright delete).
 *
 * A picture counts as used when ANY of these is true, so a photo that is in use somewhere is never touched:
 *  - a row of any table points at it by id (product photos, videos and documents, category images, page share images and
 *    every other foreign key to the asset table, found from the database itself). Being listed in the media library is
 *    NOT a use: a picture that only sits in the library is exactly what this service is for;
 *  - its file stem appears in rich text or settings (descriptions, pages, blog, layouts, configuration revisions);
 *  - it is younger than the grace period, or a demo picture.
 * Trashed pictures stay in var/media-trash for the retention period together with a manifest, so they can be restored
 * (see restore()) before they are purged.
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
    ];
    /** Tables that are revision logs but DO hold picture references (layout and settings payloads). */
    /** Foreign keys that do not make a picture "used". */
    private const NOT_A_USE = ['mc_store_media_asset'];
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
     * @param list<int>|null $ids look only at these assets (a manual selection is verified again before it is trashed)
     * @return list<array{id:int,key:string,width:int,height:int,bytes:int,created_at:string}>
     */
    public function find(int $limit = 200, int $graceDays = 30, ?array $ids = null): array
    {
        $limit = max(1, min(2000, $limit));
        $where = ["JSON_EXTRACT(COALESCE(ma.metadata, JSON_OBJECT()), '$.demo') IS NULL", 'ma.created_at < ?'];
        if ($ids !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
            if ($ids === []) {
                return [];
            }
            $where[] = 'ma.id IN (' . implode(',', $ids) . ')';
        }
        foreach ($this->idReferences() as [$table, $column]) {
            $where[] = "NOT EXISTS (SELECT 1 FROM `{$table}` r WHERE r.`{$column}`=ma.id)";
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ma.id,ma.storage_key,ma.width,ma.height,ma.bytes,ma.created_at FROM mc_media_asset ma WHERE ' . implode(' AND ', $where) . " ORDER BY ma.id LIMIT {$limit}",
            [gmdate('Y-m-d H:i:s', time() - max(0, $graceDays) * 86400)],
        );
        $found = [];
        $mentioned = $this->mentionedStems(array_values(array_filter(array_map(fn (array $row): string => $this->stemOf((string) $row['storage_key']), $rows), static fn (string $stem): bool => $stem !== '')));
        foreach ($rows as $row) {
            $key = (string) $row['storage_key'];
            if ($key !== '' && !isset($mentioned[$this->stemOf($key)])) {
                $found[] = ['id' => (int) $row['id'], 'key' => $key, 'width' => (int) $row['width'], 'height' => (int) $row['height'], 'bytes' => (int) $row['bytes'], 'created_at' => (string) $row['created_at']];
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
        foreach ($this->find($limit, $graceDays) as $asset) {
            $moved = $this->moveToTrash($asset['id'], $asset['key'], $dryRun);
            if ($moved !== null) {
                ++$result['assets'];
                $result['files'] += $moved;
            }
        }

        return $result;
    }

    /**
     * Trashes the chosen pictures. Each one is checked again right now, so a picture that became used after the list was
     * shown (or never was a candidate) is skipped, never removed.
     *
     * @param list<int> $ids
     * @return array{trashed:int,skipped:int,files:int}
     */
    public function trashAssets(array $ids, int $graceDays = 0): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        $result = ['trashed' => 0, 'skipped' => 0, 'files' => 0];
        $unused = [];
        foreach ($this->find(max(1, count($ids)), $graceDays, $ids) as $asset) {
            $unused[$asset['id']] = $asset;
        }
        foreach ($ids as $id) {
            $moved = isset($unused[$id]) ? $this->moveToTrash($id, $unused[$id]['key'], false) : null;
            if ($moved === null) {
                ++$result['skipped'];
            } else {
                ++$result['trashed'];
                $result['files'] += $moved;
            }
        }

        return $result;
    }

    /**
     * Pictures in the trash, newest first.
     *
     * @return list<array{date:string,id:int,key:string,files:int,bytes:int,trashed_at:string}>
     */
    public function listTrash(): array
    {
        $root = $this->trashRoot();
        $items = [];
        foreach (is_dir($root) ? (scandir($root) ?: []) : [] as $date) {
            if (preg_match('~^\d{8}$~', $date) !== 1 || !is_dir($root . '/' . $date . '/_assets')) {
                continue;
            }
            foreach (scandir($root . '/' . $date . '/_assets') ?: [] as $name) {
                if (preg_match('~^(\d+)\.json$~', $name, $m) !== 1) {
                    continue;
                }
                $manifest = json_decode((string) @file_get_contents($root . '/' . $date . '/_assets/' . $name), true);
                if (!is_array($manifest) || !is_array($manifest['asset'] ?? null)) {
                    continue;
                }
                $items[] = [
                    'date' => $date, 'id' => (int) $m[1], 'key' => (string) ($manifest['asset']['storage_key'] ?? ''),
                    'files' => count((array) ($manifest['files'] ?? [])), 'bytes' => (int) ($manifest['bytes'] ?? 0),
                    'trashed_at' => (string) ($manifest['trashed_at'] ?? ''),
                ];
            }
        }
        usort($items, static fn (array $a, array $b): int => [$b['date'], $b['id']] <=> [$a['date'], $a['id']]);

        return $items;
    }

    /**
     * Puts trashed pictures back: files return to public/media and the records (with their library membership) are re-created.
     *
     * @param list<array{date:string,id:int}> $items
     * @return int pictures restored
     */
    public function restore(array $items): int
    {
        $restored = 0;
        foreach ($items as $item) {
            $date = (string) ($item['date'] ?? '');
            $id = (int) ($item['id'] ?? 0);
            $file = $this->trashRoot() . '/' . $date . '/_assets/' . $id . '.json';
            if (preg_match('~^\d{8}$~', $date) !== 1 || $id < 1 || !is_file($file)) {
                continue;
            }
            $manifest = json_decode((string) file_get_contents($file), true);
            if (!is_array($manifest) || !is_array($manifest['asset'] ?? null)) {
                continue;
            }
            if ($this->connection->fetchOne('SELECT 1 FROM mc_media_asset WHERE id=? OR storage_key_hash=?', [$id, hash('sha256', (string) $manifest['asset']['storage_key'], true)]) !== false) {
                continue; // the same picture was uploaded again in the meantime: keep that one
            }
            foreach ((array) ($manifest['files'] ?? []) as $relative) {
                $relative = (string) $relative;
                if ($relative === '' || str_contains($relative, '..')) {
                    continue;
                }
                $from = $this->trashRoot() . '/' . $date . '/' . $relative;
                $to = $this->mediaRoot() . '/' . $relative;
                if (is_file($from) && !is_file($to) && (is_dir(dirname($to)) || @mkdir(dirname($to), 0755, true) || is_dir(dirname($to)))) {
                    @rename($from, $to);
                }
            }
            $this->connection->insert('mc_media_asset', $this->decodeRow($manifest['asset']));
            foreach ((array) ($manifest['store_links'] ?? []) as $link) {
                try {
                    $this->connection->insert('mc_store_media_asset', $this->decodeRow((array) $link));
                } catch (\Throwable) {
                    // the store or folder is gone: the picture is back, it is just not filed
                }
            }
            @unlink($file);
            ++$restored;
        }

        return $restored;
    }

    /** @return int|null files moved (null = nothing was done) */
    private function moveToTrash(int $assetId, string $key, bool $dryRun): ?int
    {
        $files = $this->filesOf($this->stemOf($key));
        if ($dryRun) {
            return count($files);
        }
        $day = $this->trashRoot() . '/' . gmdate('Ymd');
        if (!is_dir($day . '/_assets') && !@mkdir($day . '/_assets', 0755, true) && !is_dir($day . '/_assets')) {
            return null; // the trash is not writable: keep the picture instead of losing it
        }
        $asset = $this->connection->fetchAssociative('SELECT * FROM mc_media_asset WHERE id=?', [$assetId]);
        if (!is_array($asset)) {
            return null;
        }
        $links = $this->connection->fetchAllAssociative('SELECT * FROM mc_store_media_asset WHERE asset_id=?', [$assetId]);
        $bytes = 0;
        $moved = [];
        foreach ($files as $relative) {
            $from = $this->mediaRoot() . '/' . $relative;
            $to = $day . '/' . $relative;
            if (!is_dir(dirname($to)) && !@mkdir(dirname($to), 0755, true) && !is_dir(dirname($to))) {
                continue;
            }
            $size = (int) @filesize($from);
            if (@rename($from, $to)) {
                $moved[] = $relative;
                $bytes += $size;
            }
        }
        $manifest = ['asset' => $this->encodeRow($asset), 'store_links' => array_map(fn (array $l): array => $this->encodeRow($l), $links), 'files' => $moved, 'bytes' => $bytes, 'trashed_at' => gmdate('c')];
        if (@file_put_contents($day . '/_assets/' . $assetId . '.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            foreach ($moved as $relative) { // no manifest, no trash: put the files back
                @rename($day . '/' . $relative, $this->mediaRoot() . '/' . $relative);
            }

            return null;
        }
        $this->connection->delete('mc_media_asset', ['id' => $assetId]);

        return count($moved);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> binary values become {"hex": "..."} */
    private function encodeRow(array $row): array
    {
        foreach ($row as $column => $value) {
            if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                $row[$column] = ['hex' => bin2hex($value)];
            }
        }

        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decodeRow(array $row): array
    {
        foreach ($row as $column => $value) {
            if (is_array($value) && isset($value['hex']) && is_string($value['hex'])) {
                $row[$column] = hex2bin($value['hex']);
            }
        }

        return $row;
    }

    private function trashRoot(): string
    {
        return rtrim($this->projectDir, '/\\') . '/var/media-trash';
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
                if (!in_array((string) $row['t'], self::NOT_A_USE, true)) {
                    $pairs[$row['t'] . '.' . $row['c']] = [(string) $row['t'], (string) $row['c']];
                }
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

    /**
     * Which of the given file stems appear in any text column (rich text, pages, layouts, settings). One query per column and
     * batch of stems, not one per picture. A column that cannot be checked marks every stem as mentioned: losing a picture is
     * worse than keeping one.
     *
     * @param list<string> $stems
     * @return array<string,true>
     */
    private function mentionedStems(array $stems): array
    {
        $mentioned = [];
        $stems = array_values(array_unique($stems));
        foreach (array_chunk($stems, 40) as $batch) {
            foreach ($this->textColumns() as [$table, $column]) {
                if ($table === 'mc_media_asset') {
                    continue; // the asset's own record names its file
                }
                $open = array_values(array_filter($batch, static fn (string $stem): bool => !isset($mentioned[$stem])));
                if ($open === []) {
                    break;
                }
                try {
                    $like = implode(' OR ', array_fill(0, count($open), "CAST(`{$column}` AS CHAR) LIKE ?"));
                    $params = array_map(static fn (string $stem): string => '%' . addcslashes($stem, '%_\\') . '%', $open);
                    foreach ($this->connection->iterateColumn("SELECT CAST(`{$column}` AS CHAR) FROM `{$table}` WHERE {$like}", $params) as $text) {
                        foreach ($open as $stem) {
                            if (is_string($text) && str_contains($text, $stem)) {
                                $mentioned[$stem] = true;
                            }
                        }
                    }
                } catch (\Throwable) {
                    foreach ($open as $stem) {
                        $mentioned[$stem] = true;
                    }
                }
            }
        }

        return $mentioned;
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
