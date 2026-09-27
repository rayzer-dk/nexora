<?php

declare(strict_types=1);

namespace Commerce\Core\Recovery;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Core\Platform\PlatformVersion;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Throwable;
use ZipArchive;

final class RecoverySnapshotService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PublicIdFactory $publicIds,
        private readonly DatabaseSnapshotWriter $databaseWriter,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Creates a recovery/backup archive. Recovery profile excludes mutable user media; data/full profiles include media.
     * Runtime cache is always excluded and must be rebuilt after restore.
     *
     * @return array{id:int,public_id:string,snapshot_key:string,archive_path:string,sha256:string,size_bytes:int,status:string}
     */
    public function create(string $reason, ?string $actorSubject = null, bool $includeVendor = true, string $profile = 'recovery'): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ca2e0bd401a1'));
        }
        if (!in_array($profile, ['recovery', 'full', 'data', 'database'], true)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b6d9c506fbb1'));
        }
        $reason = trim($reason) !== '' ? mb_substr(trim($reason), 0, 190, 'UTF-8') : 'manual';
        $uuid = $this->publicIds->generate();
        $key = gmdate('Ymd-His') . '-' . substr(str_replace('-', '', $uuid->toRfc4122()), 0, 16);
        $now = $this->now();
        $this->connection->insert('mc_recovery_snapshot', [
            'public_id' => $uuid->toBinary(),
            'snapshot_key' => $key,
            'reason' => $reason,
            'platform_version' => PlatformVersion::VERSION,
            'status' => 'creating',
            'archive_path' => null,
            'archive_sha256' => null,
            'size_bytes' => null,
            'actor_subject' => $actorSubject,
            'created_at' => $now,
            'completed_at' => null,
            'verified_at' => null,
            'restored_at' => null,
            'last_error' => null,
        ]);
        $id = (int) $this->connection->lastInsertId();

        $recoveryDir = $this->recoveryDirectory();
        $workDir = $recoveryDir . '/work/' . $key;
        $archivePath = $recoveryDir . '/snapshots/' . $key . '.zip';
        try {
            $this->ensureDirectory(dirname($archivePath), 0750);
            $this->ensureDirectory($workDir . '/database', 0750);
            if ($this->connection->getTransactionNestingLevel() !== 0) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.dae379606b6c'));
            }
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->connection->beginTransaction();
            try {
                // The first consistent read establishes one InnoDB MVCC snapshot used by all table exports.
                $this->connection->fetchOne('SELECT 1');
                $databaseStats = $this->databaseWriter->write($this->connection, $workDir . '/database');
                $this->connection->commit();
            } catch (Throwable $e) {
                if ($this->connection->getTransactionNestingLevel() > 0) {
                    try { $this->connection->rollBack(); } catch (Throwable) {}
                }
                throw $e;
            }

            $zip = new ZipArchive();
            $opened = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($opened !== true) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.79879ed45bb9'));
            }

            $files = [];
            $roots = [];
            try {
                if (in_array($profile, ['recovery', 'full'], true)) {
                    foreach (ManagedPlatformPaths::recoveryDirectories(false) as $directory) {
                        $absolute = $this->projectPath($directory);
                        if (!is_dir($absolute)) {
                            continue;
                        }
                        $roots[] = $directory;
                        $this->addDirectory($zip, $absolute, 'files/' . $directory, $files);
                    }
                    if ($includeVendor && is_dir($this->projectPath('vendor'))) {
                        $roots[] = 'vendor';
                        $this->addDirectory($zip, $this->projectPath('vendor'), 'files/vendor', $files);
                    }
                    foreach (ManagedPlatformPaths::recoveryFiles() as $file) {
                        $absolute = $this->projectPath($file);
                        if (!is_file($absolute) || is_link($absolute)) {
                            continue;
                        }
                        if (!$zip->addFile($absolute, 'files/' . $file)) {
                            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.041a4d3be000') . $file . \Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.4e0dfbc0f068'));
                        }
                        $files[] = $file;
                    }
                }
                if (in_array($profile, ['full', 'data'], true) && is_dir($this->projectPath('public/media'))) {
                    $roots[] = 'public/media';
                    $this->addDirectory($zip, $this->projectPath('public/media'), 'files/public/media', $files);
                }
                if (in_array($profile, ['full', 'data'], true) && is_dir($this->projectPath('var/storage/digital'))) {
                    $roots[] = 'var/storage/digital';
                    $this->addDirectory($zip, $this->projectPath('var/storage/digital'), 'files/var/storage/digital', $files);
                }

                $ignoredDatabaseFiles = [];
                $this->addDirectory($zip, $workDir . '/database', 'database', $ignoredDatabaseFiles);
                $manifest = [
                    'format' => 1,
                    'snapshot_key' => $key,
                    'public_id' => $uuid->toRfc4122(),
                    'platform_version' => PlatformVersion::VERSION,
                    'created_at' => gmdate('c'),
                    'reason' => $reason,
                    'profile' => $profile,
                    'managed_roots' => array_values(array_unique($roots)),
                    'managed_files' => array_values(array_unique($files)),
                    'database' => $databaseStats,
                    'mutable_paths_excluded' => $profile === 'full' ? ['var'] : ($profile === 'data' ? ['var'] : ['var', 'public/media']),
                ];
                $manifestJson = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
                if (!$zip->addFromString('snapshot.json', $manifestJson)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.fb871b2e6c89'));
                }
            } finally {
                $zip->close();
            }

            @chmod($archivePath, 0600);
            $sha256 = hash_file('sha256', $archivePath);
            $size = (int) (@filesize($archivePath) ?: 0);
            if (!is_string($sha256) || strlen($sha256) !== 64 || $size < 1) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3ca7d10336b4'));
            }
            $this->connection->update('mc_recovery_snapshot', [
                'status' => 'ready',
                'archive_path' => $this->relativeArchivePath($archivePath),
                'archive_sha256' => $sha256,
                'size_bytes' => $size,
                'completed_at' => $this->now(),
                'verified_at' => $this->now(),
                'last_error' => null,
            ], ['id' => $id]);

            return [
                'id' => $id,
                'public_id' => $uuid->toRfc4122(),
                'snapshot_key' => $key,
                'archive_path' => $archivePath,
                'sha256' => $sha256,
                'size_bytes' => $size,
                'status' => 'ready',
            ];
        } catch (Throwable $e) {
            try {
                $this->connection->update('mc_recovery_snapshot', [
                    'status' => 'failed',
                    'last_error' => mb_substr(preg_replace('/[\r\n\t]+/', ' ', $e->getMessage()) ?: 'snapshot failed', 0, 1000, 'UTF-8'),
                    'completed_at' => $this->now(),
                ], ['id' => $id]);
            } catch (Throwable) {
                // Snapshot failure must not cascade into a second database failure.
            }
            @unlink($archivePath);
            throw $e;
        } finally {
            $this->removeTree($workDir);
        }
    }


    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT id,public_id,snapshot_key,reason,platform_version,status,archive_path,archive_sha256,size_bytes,actor_subject,created_at,completed_at,verified_at,restored_at,last_error FROM mc_recovery_snapshot WHERE id=? LIMIT 1', [$id]);
        if (!is_array($row)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.369110fde955'));
        }
        $row['id'] = (int) $row['id'];
        $row['public_id'] = Uuid::fromBinary((string) $row['public_id'])->toRfc4122();
        $row['size_bytes'] = $row['size_bytes'] === null ? null : (int) $row['size_bytes'];
        $row['archive_path_absolute'] = is_string($row['archive_path']) && $row['archive_path'] !== '' ? $this->absoluteArchivePath((string) $row['archive_path']) : null;
        return $row;
    }

    public function delete(int $id): void
    {
        $row = $this->get($id);
        $path = $row['archive_path_absolute'];
        if (is_string($path) && is_file($path)) {
            @unlink($path);
        }
        $this->connection->delete('mc_recovery_snapshot', ['id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public function list(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id,public_id,snapshot_key,reason,platform_version,status,archive_path,archive_sha256,size_bytes,actor_subject,created_at,completed_at,verified_at,restored_at,last_error FROM mc_recovery_snapshot ORDER BY id DESC LIMIT ' . $limit,
        );
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['public_id'] = Uuid::fromBinary((string) $row['public_id'])->toRfc4122();
            $row['size_bytes'] = $row['size_bytes'] === null ? null : (int) $row['size_bytes'];
            $row['archive_exists'] = is_string($row['archive_path']) && $row['archive_path'] !== '' && is_file($this->absoluteArchivePath((string) $row['archive_path']));
        }
        unset($row);
        return $rows;
    }

    /** @return array{ok:bool,reason:string,sha256:?string,size_bytes:int} */
    public function verify(int $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT id,archive_path,archive_sha256 FROM mc_recovery_snapshot WHERE id=? LIMIT 1', [$id]);
        if (!is_array($row) || !is_string($row['archive_path']) || $row['archive_path'] === '') {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.369110fde955'));
        }
        $path = $this->absoluteArchivePath((string) $row['archive_path']);
        if (!is_file($path)) {
            return ['ok' => false, 'reason' => 'archive_missing', 'sha256' => null, 'size_bytes' => 0];
        }
        $actual = hash_file('sha256', $path);
        $expected = (string) ($row['archive_sha256'] ?? '');
        $zip = new ZipArchive();
        $open = $zip->open($path, ZipArchive::RDONLY);
        $structureOk = $open === true && $zip->locateName('snapshot.json') !== false && $zip->locateName('database/schema.json') !== false;
        if ($open === true) {
            $zip->close();
        }
        $ok = is_string($actual) && strlen($actual) === 64 && $expected !== '' && hash_equals(strtolower($expected), strtolower($actual)) && $structureOk;
        if ($ok) {
            $this->connection->update('mc_recovery_snapshot', ['verified_at' => $this->now(), 'last_error' => null], ['id' => $id]);
        }
        return [
            'ok' => $ok,
            'reason' => $ok ? 'verified' : ($structureOk ? 'checksum_mismatch' : 'invalid_archive'),
            'sha256' => is_string($actual) ? $actual : null,
            'size_bytes' => (int) (@filesize($path) ?: 0),
        ];
    }

    public function archivePathForKey(string $snapshotKey): string
    {
        if (preg_match('/^[A-Za-z0-9._-]{8,96}$/D', $snapshotKey) !== 1) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.e3abeaf84882'));
        }
        return $this->recoveryDirectory() . '/snapshots/' . $snapshotKey . '.zip';
    }

    private function addDirectory(ZipArchive $zip, string $absoluteRoot, string $zipRoot, array &$files): void
    {
        $rootReal = realpath($absoluteRoot);
        if (!is_string($rootReal)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootReal, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || !$item->isFile() || $item->isLink()) {
                continue;
            }
            $absolute = $item->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen($rootReal))), '/');
            if ($relative === '' || str_contains($relative, '../')) {
                continue;
            }
            $archiveName = rtrim($zipRoot, '/') . '/' . $relative;
            if (!$zip->addFile($absolute, $archiveName)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.adee7630a8c2') . $archiveName . '.');
            }
            if (str_starts_with($archiveName, 'files/')) {
                $files[] = substr($archiveName, strlen('files/'));
            }
        }
    }

    private function projectPath(string $relative): string
    {
        return rtrim($this->projectDir, '/\\') . '/' . ltrim($relative, '/\\');
    }

    private function recoveryDirectory(): string
    {
        return $this->projectPath('var/recovery');
    }

    private function relativeArchivePath(string $absolute): string
    {
        $base = rtrim($this->projectDir, '/\\') . '/';
        return str_starts_with($absolute, $base) ? substr($absolute, strlen($base)) : $absolute;
    }

    private function absoluteArchivePath(string $stored): string
    {
        if (str_starts_with($stored, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $stored) === 1) {
            return $stored;
        }
        return $this->projectPath($stored);
    }

    private function ensureDirectory(string $directory, int $mode): void
    {
        if (!is_dir($directory) && !@mkdir($directory, $mode, true) && !is_dir($directory)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.229ef84938d3'));
        }
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($directory);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
