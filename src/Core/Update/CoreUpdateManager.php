<?php

declare(strict_types=1);

namespace Commerce\Core\Update;

use Commerce\Core\Health\RequirementLevel;
use Commerce\Core\Health\SystemPreflightInspector;
use Commerce\Core\Id\PublicIdFactory;
use Commerce\Core\Platform\PlatformVersion;
use Commerce\Core\Recovery\RecoveryArchiveRestorer;
use Commerce\Core\Recovery\RecoverySnapshotService;
use Commerce\Core\Runtime\MaintenanceMode;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Throwable;

final class CoreUpdateManager
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PublicIdFactory $publicIds,
        private readonly CoreUpdatePackageInspector $packages,
        private readonly UpdatePreflight $updatePreflight,
        private readonly ActiveExtensionCompatibilityInspector $extensionCompatibility,
        private readonly SystemPreflightInspector $systemPreflight,
        private readonly RecoverySnapshotService $recovery,
        private readonly RecoveryArchiveRestorer $restorer,
        private readonly AtomicCoreReleaseSwitcher $switcher,
        private readonly CoreUpdateMigrationRunner $migrations,
        private readonly StagedCoreReleaseValidator $releaseValidator,
        private readonly CoreUpdateHttpProbe $httpProbe,
        private readonly MaintenanceMode $maintenance,
        private readonly string $projectDir,
        private readonly string $publicKey,
    ) {
    }

    /** @return array{id:int,public_id:string,target_version:string,status:string,bundle_sha256:string} */
    public function stage(string $uploadedBundle): array
    {
        $inspection = $this->packages->inspect($uploadedBundle, $this->publicKey);
        /** @var UpdateManifest $manifest */
        $manifest = $inspection['manifest'];
        $errors = $this->preflightErrors($manifest);
        if ($errors !== []) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f33aef75612b') . implode(' | ', $errors));
        }
        if (version_compare($manifest->version, PlatformVersion::VERSION, '<=')) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.e59bfc21c9fb'));
        }

        $uuid = $this->publicIds->generate();
        $directory = $this->projectPath('var/update/inbox');
        $this->ensureDirectory($directory, 0700);
        $target = $directory . '/' . $manifest->version . '-' . substr(str_replace('-', '', $uuid->toRfc4122()), 0, 12) . '.zip';
        if (!@copy($uploadedBundle, $target)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3a00e1fb3ce0'));
        }
        @chmod($target, 0600);
        $copiedHash = hash_file('sha256', $target);
        if (!is_string($copiedHash) || !hash_equals($inspection['bundle_sha256'], $copiedHash)) {
            @unlink($target);
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.8ce4b0cb4827'));
        }

        $now = $this->now();
        $this->connection->insert('mc_update_attempt', [
            'public_id' => $uuid->toBinary(),
            'target_version' => $manifest->version,
            'channel' => $manifest->channel,
            'status' => 'staged',
            'package_sha256' => $copiedHash,
            'rollback_snapshot_id' => null,
            'staged_path' => $this->relativePath($target),
            'error_summary' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'completed_at' => null,
        ]);
        $id = (int) $this->connection->lastInsertId();

        return ['id' => $id, 'public_id' => $uuid->toRfc4122(), 'target_version' => $manifest->version, 'status' => 'staged', 'bundle_sha256' => $copiedHash];
    }

    /** @return array{status:string,target_version:string,rollback_snapshot:string|null,migrations:int,statements:int,smoke:array<string,mixed>} */
    public function apply(int $attemptId, ?string $actor = null): array
    {
        if ($this->maintenance->isEnabled()) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9d0b931d0711'));
        }

        $this->acquireUpdateLock();
        try {
            // Re-check after obtaining the database lock. This closes the race where two
            // administrators start an update before either request has enabled maintenance.
            if ($this->maintenance->isEnabled()) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0790da5133a1'));
            }

            $row = $this->connection->fetchAssociative('SELECT * FROM mc_update_attempt WHERE id=? LIMIT 1', [$attemptId]);
            if (!is_array($row)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.de108ad96d18'));
            }
            if ((string) $row['status'] !== 'staged') {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.c90c44d3f6ea'));
            }
            $bundlePath = $this->absolutePath((string) $row['staged_path']);
            if (!is_file($bundlePath)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.03f820bcb66b'));
            }
            $bundleHash = hash_file('sha256', $bundlePath);
            if (!is_string($bundleHash) || !hash_equals((string) $row['package_sha256'], $bundleHash)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b2aa76ade6a2'));
            }

            $inspection = $this->packages->inspect($bundlePath, $this->publicKey);
            /** @var UpdateManifest $manifest */
            $manifest = $inspection['manifest'];
            if ($manifest->version !== (string) $row['target_version']) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3d036060981d'));
            }
            $errors = $this->preflightErrors($manifest);
            if ($errors !== []) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f33aef75612b') . implode(' | ', $errors));
            }

            $stage = $this->projectPath('var/update/stage/attempt-' . $attemptId . '-' . bin2hex(random_bytes(4)));
            $snapshot = null;
            $migrationResult = ['migrations' => 0, 'statements' => 0];
            $smoke = [];
            $this->updateAttempt($attemptId, 'preparing', null);

            try {
                $release = $this->packages->extractRelease($bundlePath, $this->publicKey, $stage);
                $this->releaseValidator->validate($release['release_dir'], $manifest->version);
                $this->assertMigrationPlanCoversNewMigrations($release['release_dir']);

                // Freeze all writes before the rollback point so successful orders cannot
                // disappear from backup. Visitors receive a dependency-free maintenance
                // response and never execute a half-updated release.
                $this->maintenance->enable('core-update:' . $manifest->version, 30);
                $snapshot = $this->recovery->create('pre-core-update:' . $manifest->version, $actor, true);
                $snapshotVerification = $this->recovery->verify((int) $snapshot['id']);
                if (!($snapshotVerification['ok'] ?? false)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ac92e4d23219'));
                }
                $this->connection->update('mc_update_attempt', [
                    'status' => 'applying',
                    'rollback_snapshot_id' => $snapshot['id'],
                    'updated_at' => $this->now(),
                    'error_summary' => null,
                ], ['id' => $attemptId]);

                // Database changes happen while the old release is still physically intact
                // and maintenance blocks all normal traffic. If a migration fails, restoring
                // the verified snapshot is enough; no Core files have been activated yet.
                $migrationResult = $this->migrations->run($release['release_dir']);

                // Switch platform-owned paths only after the migration plan has succeeded.
                // The maintenance bootstrap continues to shield visitors from the new code.
                $this->switcher->apply($release['release_dir']);

                // Now boot the newly switched release through the real HTTP entry point while
                // maintenance remains active. Only the private one-time probe token can bypass
                // maintenance. Any bootstrap/container/route/DB failure triggers full rollback.
                $smoke = $this->httpProbe->run($manifest->version);
                $this->postApplyHealth($manifest->version);

                $this->connection->update('mc_update_attempt', [
                    'status' => 'completed',
                    'updated_at' => $this->now(),
                    'completed_at' => $this->now(),
                    'error_summary' => null,
                ], ['id' => $attemptId]);
                $this->maintenance->disable();
                $this->removeTree($stage);

                return [
                    'status' => 'completed',
                    'target_version' => $manifest->version,
                    'rollback_snapshot' => $snapshot['snapshot_key'],
                    'migrations' => $migrationResult['migrations'],
                    'statements' => $migrationResult['statements'],
                    'smoke' => $smoke,
                ];
            } catch (Throwable $e) {
                $message = $this->safeMessage($e);
                if (is_array($snapshot) && isset($snapshot['archive_path']) && is_file((string) $snapshot['archive_path'])) {
                    try {
                        $this->restorer->restore((string) $snapshot['archive_path']);
                        $this->updateAttempt($attemptId, 'rolled_back', $message);
                    } catch (Throwable $rollbackError) {
                        $this->updateAttempt($attemptId, 'rollback_failed', $message . ' | rollback: ' . $this->safeMessage($rollbackError));
                        // Recovery intentionally leaves maintenance enabled after rollback failure.
                        throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b8eef13ce0ea'), 0, $rollbackError);
                    }
                } else {
                    try { $this->maintenance->disable(); } catch (Throwable) {}
                    $this->updateAttempt($attemptId, 'failed', $message);
                }
                $this->removeTree($stage);
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5fb2deda2718') . $message, 0, $e);
            }
        } finally {
            $this->releaseUpdateLock();
        }
    }

    /** @return list<array<string,mixed>> */
    public function list(int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ua.id,ua.public_id,ua.target_version,ua.channel,ua.status,ua.package_sha256,ua.rollback_snapshot_id,ua.staged_path,ua.error_summary,ua.created_at,ua.updated_at,ua.completed_at,rs.snapshot_key AS rollback_snapshot_key FROM mc_update_attempt ua LEFT JOIN mc_recovery_snapshot rs ON rs.id=ua.rollback_snapshot_id ORDER BY ua.id DESC LIMIT ' . $limit,
        );
        foreach ($rows as &$item) {
            $item['id'] = (int) $item['id'];
            $item['public_id'] = Uuid::fromBinary((string) $item['public_id'])->toRfc4122();
            $item['bundle_exists'] = is_string($item['staged_path']) && $item['staged_path'] !== '' && is_file($this->absolutePath((string) $item['staged_path']));
        }
        unset($item);
        return $rows;
    }

    /** @return list<string> */
    private function preflightErrors(UpdateManifest $manifest): array
    {
        $errors = $this->updatePreflight->validate($manifest);
        $errors = [...$errors, ...$this->extensionCompatibility->validateTarget($manifest->version, $manifest->extensionApi)];
        foreach ([...$this->systemPreflight->runtime(), ...$this->systemPreflight->database($this->connection)] as $result) {
            if (!$result->passed && $result->level === RequirementLevel::Required) {
                $errors[] = $result->label . ': ' . ($result->action ?? $result->required);
            }
        }
        return array_values(array_unique($errors));
    }

    private function assertMigrationPlanCoversNewMigrations(string $releaseDir): void
    {
        $current = array_map('basename', glob($this->projectPath('migrations/*.php')) ?: []);
        $next = array_map('basename', glob(rtrim($releaseDir, '/\\') . '/migrations/*.php') ?: []);
        $new = array_values(array_diff($next, $current));
        if ($new === []) {
            return;
        }
        $planned = $this->migrations->plannedVersions($releaseDir);
        foreach ($new as $filename) {
            $class = 'Commerce\\Migrations\\' . pathinfo($filename, PATHINFO_FILENAME);
            if (!in_array($class, $planned, true)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.982d982f8a32') . $filename . \Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.538355ab3931'));
            }
        }
    }

    private function postApplyHealth(string $expectedVersion): void
    {
        $releasePath = $this->projectPath('resources/platform/release.json');
        if (!is_file($releasePath) || !is_file($this->projectPath('public/index.php')) || !is_file($this->projectPath('bootstrap/emergency.php'))) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.c9cc94618141'));
        }
        $release = json_decode((string) file_get_contents($releasePath), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($release) || (string) ($release['version'] ?? '') !== $expectedVersion) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7414854d9a51'));
        }
        if ((int) $this->connection->fetchOne('SELECT 1') !== 1) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2d4d7745995a'));
        }
    }

    private function updateAttempt(int $id, string $status, ?string $error): void
    {
        try {
            $this->connection->update('mc_update_attempt', [
                'status' => $status,
                'error_summary' => $error === null ? null : mb_substr($error, 0, 1000, 'UTF-8'),
                'updated_at' => $this->now(),
                'completed_at' => in_array($status, ['completed', 'failed', 'rolled_back', 'rollback_failed'], true) ? $this->now() : null,
            ], ['id' => $id]);
        } catch (Throwable) {
            // Update status is diagnostic; it must never mask the original failure or rollback path.
        }
    }

    private function relativePath(string $path): string
    {
        $base = rtrim($this->projectDir, '/\\') . '/';
        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }
        return $this->projectPath($path);
    }

    private function projectPath(string $relative): string
    {
        return rtrim($this->projectDir, '/\\') . '/' . ltrim($relative, '/\\');
    }

    private function ensureDirectory(string $directory, int $mode): void
    {
        if (!is_dir($directory) && !@mkdir($directory, $mode, true) && !is_dir($directory)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.e381a493c153'));
        }
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            @unlink($directory);
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }

    private function acquireUpdateLock(): void
    {
        try {
            $acquired = (int) $this->connection->fetchOne("SELECT GET_LOCK('nexora_commerce_core_update', 0)");
        } catch (Throwable $e) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9dc772923583'), 0, $e);
        }
        if ($acquired !== 1) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ba978b5181c5'));
        }
    }

    private function releaseUpdateLock(): void
    {
        try {
            $this->connection->fetchOne("SELECT RELEASE_LOCK('nexora_commerce_core_update')");
        } catch (Throwable) {
            // The server releases named locks automatically when the connection closes.
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private function safeMessage(Throwable $e): string
    {
        return mb_substr(preg_replace('/[\r\n\t]+/', ' ', $e->getMessage()) ?: 'update failed', 0, 700, 'UTF-8');
    }
}
