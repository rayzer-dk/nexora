<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

require_once dirname(__DIR__) . '/I18n/TranslationCatalogLoader.php';

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Core\I18n\TranslationCatalogLoader;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use ZipArchive;

final readonly class ExtensionPackageManager
{
    public function __construct(
        private Connection $connection,
        private PublicIdFactory $publicIds,
        private ExtensionPackageValidator $validator,
        private ExtensionConflictGuard $conflicts,
        private ExtensionMigrationRunner $extensionMigrations,
        private string $projectDir,
    ) {
    }

    /** @return array{id:int,code:string,version:string,status:string,quarantined:bool,warnings:list<string>} */
    public function install(string $archivePath): array
    {
        $inspection = $this->validator->inspect($archivePath);
        $manifest = $inspection->manifest;
        $this->conflicts->assertInstallable($manifest);
        $code = (string) $manifest['code'];
        $version = (string) $manifest['version'];
        $hash = hash_file('sha256', $archivePath);
        if (!is_string($hash)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.cbb1c781a1ec'));
        }

        $namespace = TranslationCatalogLoader::namespaceForCode($code);
        $installedCodes = $this->connection->fetchFirstColumn('SELECT DISTINCT code FROM mc_extension_installation WHERE code<>?', [$code]);
        foreach ($installedCodes as $installedCode) {
            if (is_string($installedCode) && TranslationCatalogLoader::namespaceForCode($installedCode) === $namespace) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.localization.namespace_collision') . $installedCode);
            }
        }

        $existing = $this->connection->fetchAssociative('SELECT id,status,package_sha256 FROM mc_extension_installation WHERE code=? AND version=? LIMIT 1', [$code, $version]);
        if (is_array($existing)) {
            if (!hash_equals((string) $existing['package_sha256'], $hash)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.929895e8005f'));
            }
            return [
                'id' => (int) $existing['id'], 'code' => $code, 'version' => $version,
                'status' => (string) $existing['status'], 'quarantined' => (string) $existing['status'] === 'quarantined',
                'warnings' => $inspection->warnings,
            ];
        }

        $now = $this->now();
        $status = $inspection->quarantined ? 'quarantined' : 'staged';
        $installPath = $inspection->quarantined
            ? $this->quarantineArchive($archivePath, $code, $version)
            : ((string) ($manifest['execution'] ?? 'declarative') === 'trusted_release'
                ? $this->extractTrustedPackage($archivePath, $code, $version, $inspection->files)
                : $this->extractDeclarativePackage($archivePath, $code, $version, $inspection->files));

        $this->connection->insert('mc_extension_installation', [
            'public_id' => $this->publicIds->binary(),
            'code' => $code,
            'name' => mb_substr(trim((string) $manifest['name']), 0, 190, 'UTF-8'),
            'version' => $version,
            'extension_type' => (string) $manifest['type'],
            'api_version' => (string) $manifest['extension_api'],
            'status' => $status,
            'package_sha256' => $hash,
            'install_path' => $installPath,
            'manifest_json' => json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'failure_count' => 0,
            'last_error' => $inspection->quarantined ? mb_substr($inspection->quarantineReason, 0, 1000, 'UTF-8') : null,
            'installed_at' => $now,
            'activated_at' => null,
            'disabled_at' => null,
        ]);
        $id = (int) $this->connection->lastInsertId();
        $this->recordLifecycle($id, $code, $version, 'install', null, $status, $inspection->quarantined ? 'warning' : 'success', $inspection->quarantineReason ?: null);

        return ['id' => $id, 'code' => $code, 'version' => $version, 'status' => $status, 'quarantined' => $inspection->quarantined, 'warnings' => $inspection->warnings];
    }

    public function activate(int $id, bool $allowDowngrade = false): void
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM mc_extension_installation WHERE id=? LIMIT 1', [$id]);
        if (!is_array($row)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3bec61aaafaa'));
        }
        if ((string) $row['status'] === 'quarantined') {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5270a825a1dc'));
        }
        if (!is_dir((string) $row['install_path'])) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.18a9f4b1660f'));
        }

        $manifest = json_decode((string) ($row['manifest_json'] ?? '{}'), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.d618dd557266'));
        }

        $previousActive = null;
        try {
            $previousActive = $this->connection->fetchAssociative(
                "SELECT id,code,version,status FROM mc_extension_installation WHERE code=? AND status='active' AND id<>? ORDER BY activated_at DESC,id DESC LIMIT 1",
                [(string) $row['code'], (int) $row['id']]
            );
        } catch (\Throwable) {
            $previousActive = null;
        }
        if (!$allowDowngrade && is_array($previousActive) && version_compare((string) $row['version'], (string) $previousActive['version'], '<')) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.rollback_action_required'));
        }

        $newlyApplied = [];
        try {
            // Extension migrations are reversible. If any preparation/switch step fails,
            // only migrations applied during this activation are compensated in reverse order.
            $newlyApplied = $this->extensionMigrations->apply((int) $row['id'], (string) $row['install_path'], $manifest);
            $this->syncDeclaredPermissions($manifest);
            $this->publishDeclaredAssets((string) $row['code'], (string) $row['version'], (string) $row['install_path'], $manifest);

            // Assets are published before pointer switch. They remain inert until this installation is active.
            if ((string) $row['extension_type'] === 'theme') {
                $this->publishThemeAssets((string) $row['code'], (string) $row['version'], (string) $row['install_path']);
            }

            $this->connection->transactional(function (Connection $db) use ($row): void {
                $locked = $db->fetchAssociative('SELECT id,status FROM mc_extension_installation WHERE id=? FOR UPDATE', [(int) $row['id']]);
                if (!is_array($locked) || (string) $locked['status'] === 'quarantined') {
                    throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.760e60f8c0fa'));
                }
                $now = $this->now();
                $db->executeStatement("UPDATE mc_extension_installation SET status='disabled',disabled_at=? WHERE code=? AND status='active' AND id<>?", [$now, (string) $row['code'], (int) $row['id']]);
                if ((string) $row['extension_type'] === 'theme') {
                    $db->executeStatement("UPDATE mc_extension_installation SET status='disabled',disabled_at=? WHERE extension_type='theme' AND status='active' AND id<>?", [$now, (int) $row['id']]);
                }
                $db->update('mc_extension_installation', [
                    'status' => 'active',
                    'activated_at' => $now,
                    'disabled_at' => null,
                    'last_error' => null,
                ], ['id' => (int) $row['id']]);
            });

            if (is_array($previousActive)) {
                $this->recordLifecycle((int) $previousActive['id'], (string) $previousActive['code'], (string) $previousActive['version'], 'superseded', 'active', 'disabled', 'success', 'Replaced by version ' . (string) $row['version'] . '.');
                $this->recordLifecycle((int) $row['id'], (string) $row['code'], (string) $row['version'], 'update_activate', (string) $row['status'], 'active', 'success', 'Updated from version ' . (string) $previousActive['version'] . '.');
            } else {
                $this->recordLifecycle((int) $row['id'], (string) $row['code'], (string) $row['version'], 'activate', (string) $row['status'], 'active', 'success', null);
            }
        } catch (\Throwable $e) {
            try {
                $this->extensionMigrations->rollbackApplied((int) $row['id'], (string) $row['install_path'], $manifest, $newlyApplied);
            } catch (\Throwable $rollbackError) {
                $e = new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.activation_compensation_failed') . $rollbackError->getMessage(), 0, $e);
            }
            $this->recordActivationFailure((int) $row['id'], (int) $row['failure_count'], $e->getMessage());
            $this->recordLifecycle((int) $row['id'], (string) $row['code'], (string) $row['version'], 'activate', (string) $row['status'], (string) $row['status'], 'error', $e->getMessage());
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7caf52e519ee'), 0, $e);
        }
    }

    public function disable(int $id): void
    {
        $row = $this->connection->fetchAssociative('SELECT id,code,version,status FROM mc_extension_installation WHERE id=? LIMIT 1', [$id]);
        if (!is_array($row)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3bec61aaafaa'));
        }
        if ((string) $row['status'] === 'quarantined') {
            return;
        }
        $this->connection->update('mc_extension_installation', ['status' => 'disabled', 'disabled_at' => $this->now()], ['id' => $id]);
        $this->recordLifecycle($id, (string) $row['code'], (string) $row['version'], 'disable', (string) $row['status'], 'disabled', 'success', null);
    }

    /**
     * Removes one installed version that is not active. Files, published assets, settings and history links of that
     * version are deleted. With $purgeData the package's reversible `down` migrations are executed first, otherwise
     * tables created by the module are retained (module migrations must therefore be idempotent on reinstall).
     *
     * @return array{code:string,version:string,purged:bool}
     */
    public function uninstall(int $id, bool $purgeData = false): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM mc_extension_installation WHERE id=? LIMIT 1', [$id]);
        if (!is_array($row)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3bec61aaafaa'));
        }
        $status = (string) $row['status'];
        if ($status === 'active') {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.uninstall_active'));
        }
        $code = (string) $row['code'];
        $version = (string) $row['version'];
        $manifest = json_decode((string) ($row['manifest_json'] ?? '{}'), true) ?: [];
        $installPath = (string) $row['install_path'];

        if ($purgeData && $status !== 'quarantined' && is_dir($installPath)) {
            $this->extensionMigrations->rollbackInstallation($id, $installPath, is_array($manifest) ? $manifest : []);
        }

        $this->connection->transactional(function (Connection $db) use ($id): void {
            $db->executeStatement('DELETE FROM mc_extension_installation WHERE id=?', [$id]);
        });

        $root = realpath(rtrim($this->projectDir, '/\\') . '/var/extensions');
        $real = realpath($installPath);
        if ($root !== false && $real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            $this->removeTree($real);
            $parent = dirname($real);
            if ($parent !== $root && count(glob($parent . '/*') ?: []) === 0) {
                @rmdir($parent);
            }
        }
        $assets = rtrim($this->projectDir, '/\\') . '/public/media/extensions/' . $code;
        if (preg_match('/^[a-z0-9_.-]+$/i', $code) === 1 && preg_match('/^[a-z0-9_.+-]+$/i', $version) === 1) {
            $this->removeTree($assets . '/' . $version);
            if (is_dir($assets) && count(glob($assets . '/*') ?: []) === 0) {
                @rmdir($assets);
            }
        }
        $this->recordLifecycle(null, $code, $version, 'uninstall', $status, null, 'success', $purgeData ? 'Module data purged.' : 'Module data retained.');

        return ['code' => $code, 'version' => $version, 'purged' => $purgeData];
    }

    /**
     * Atomically returns an active optional extension to the newest previously installed safe version.
     * For themes the previous theme may have a different code; the built-in storefront remains the final fallback.
     *
     * @return array{id:int,code:string,version:string}
     */
    public function rollbackPrevious(int $activeId): array
    {
        $current = $this->connection->fetchAssociative(
            'SELECT id,code,version,extension_type,status,install_path,manifest_json FROM mc_extension_installation WHERE id=? LIMIT 1',
            [$activeId],
        );
        if (!is_array($current)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3bec61aaafaa'));
        }
        if ((string) $current['status'] !== 'active') {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6adcbeef8e82'));
        }

        if ((string) $current['extension_type'] === 'theme') {
            $candidate = $this->connection->fetchAssociative(
                "SELECT id,code,version FROM mc_extension_installation WHERE extension_type='theme' AND id<>? AND status IN ('disabled','staged') ORDER BY COALESCE(disabled_at,activated_at,installed_at) DESC,id DESC LIMIT 1",
                [$activeId],
            );
        } else {
            $candidate = $this->connection->fetchAssociative(
                "SELECT id,code,version FROM mc_extension_installation WHERE code=? AND id<>? AND status IN ('disabled','staged') ORDER BY COALESCE(disabled_at,activated_at,installed_at) DESC,id DESC LIMIT 1",
                [(string) $current['code'], $activeId],
            );
        }
        if (!is_array($candidate)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.043542f1bbdc'));
        }

        $currentManifest = json_decode((string) ($current['manifest_json'] ?? '{}'), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($currentManifest)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.rollback_manifest_invalid'));
        }

        // Reverse only the active version's delta first. If the candidate cannot be activated,
        // re-apply the current delta before returning the error so the active pointer and DB stay aligned.
        $this->extensionMigrations->rollbackInstallation((int) $current['id'], (string) $current['install_path'], $currentManifest);
        try {
            $this->activate((int) $candidate['id'], true);
        } catch (\Throwable $e) {
            try {
                $this->extensionMigrations->apply((int) $current['id'], (string) $current['install_path'], $currentManifest);
            } catch (\Throwable $restoreError) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.rollback_restore_failed') . $restoreError->getMessage(), 0, $e);
            }
            throw $e;
        }
        $this->recordLifecycle((int) $candidate['id'], (string) $candidate['code'], (string) $candidate['version'], 'rollback', 'disabled', 'active', 'success', 'Restored previous verified version and reversed newer migrations.');

        return [
            'id' => (int) $candidate['id'],
            'code' => (string) $candidate['code'],
            'version' => (string) $candidate['version'],
        ];
    }

    /**
     * Emergency isolation switch. Built-in platform functionality is untouched; only optional packages are disabled.
     */
    public function disableAllOptional(): int
    {
        try {
            return $this->connection->transactional(function (Connection $db): int {
                return $db->executeStatement(
                    "UPDATE mc_extension_installation SET status='disabled',disabled_at=? WHERE status='active'",
                    [$this->now()],
                );
            });
        } catch (\Throwable $e) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ce7b129e5838'), 0, $e);
        }
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id,public_id,code,name,version,extension_type,api_version,status,package_sha256,failure_count,last_error,installed_at,activated_at,disabled_at,manifest_json FROM mc_extension_installation ORDER BY installed_at DESC,id DESC');
        $normalized = array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['public_id'] = Uuid::fromBinary((string) $row['public_id'])->toRfc4122();
            $row['failure_count'] = (int) $row['failure_count'];
            $row['rollback_id'] = null;
            $row['rollback_version'] = null;
            try {
                $manifest = json_decode((string) ($row['manifest_json'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
                $row['has_settings'] = is_array($manifest) && isset($manifest['settings_schema']);
                $row['execution'] = is_array($manifest) ? (string) ($manifest['execution'] ?? 'declarative') : 'unknown';
                $row['publisher_name'] = is_array($manifest) && is_array($manifest['publisher'] ?? null) ? (string) ($manifest['publisher']['name'] ?? '') : '';
                $row['capabilities'] = is_array($manifest) && is_array($manifest['capabilities'] ?? null) ? array_values(array_filter(array_map('strval', $manifest['capabilities']))) : [];
                $row['permissions_count'] = is_array($manifest) && is_array($manifest['permissions'] ?? null) ? count($manifest['permissions']) : 0;
                $row['dependencies_count'] = is_array($manifest) && is_array($manifest['dependencies'] ?? null) ? count($manifest['dependencies']) : 0;
                $row['commercial_model'] = is_array($manifest) && is_array($manifest['commercial'] ?? null) ? (string)($manifest['commercial']['model'] ?? 'free') : 'free';
            } catch (\Throwable) {
                $row['has_settings'] = false;
                $row['execution'] = 'unknown';
                $row['publisher_name'] = '';
                $row['capabilities'] = [];
                $row['permissions_count'] = 0;
                $row['dependencies_count'] = 0;
                $row['commercial_model'] = 'free';
            }
            unset($row['manifest_json']);
            return $row;
        }, $rows);

        $familyVersions = [];
        foreach ($normalized as $item) {
            $family = (string)$item['code'];
            $familyVersions[$family][] = ['version'=>(string)$item['version'],'status'=>(string)$item['status'],'id'=>(int)$item['id']];
        }
        foreach ($normalized as $index => $item) {
            $normalized[$index]['installed_versions'] = $familyVersions[(string)$item['code']] ?? [];
        }

        foreach ($normalized as $index => $row) {
            if ((string) $row['status'] !== 'active') {
                continue;
            }
            foreach ($normalized as $candidate) {
                if ((int) $candidate['id'] === (int) $row['id'] || !in_array((string) $candidate['status'], ['disabled', 'staged'], true)) {
                    continue;
                }
                $sameFamily = (string) $row['extension_type'] === 'theme'
                    ? (string) $candidate['extension_type'] === 'theme'
                    : (string) $candidate['code'] === (string) $row['code'];
                if ($sameFamily) {
                    $normalized[$index]['rollback_id'] = (int) $candidate['id'];
                    $normalized[$index]['rollback_version'] = (string) $candidate['version'];
                    break;
                }
            }
        }

        return $normalized;
    }

    /** @return list<array<string,mixed>> */
    public function lifecycle(int $limit = 100, ?string $code = null): array
    {
        $limit = max(1, min(500, $limit));
        try {
            if (!$this->connection->createSchemaManager()->tablesExist(['mc_extension_lifecycle_event'])) {
                return [];
            }
            if ($code !== null && $code !== '') {
                return $this->connection->fetchAllAssociative(
                    'SELECT id,installation_id,code,version,action,from_status,to_status,result,message,created_at FROM mc_extension_lifecycle_event WHERE code=? ORDER BY id DESC LIMIT ' . $limit,
                    [$code]
                );
            }
            return $this->connection->fetchAllAssociative(
                'SELECT id,installation_id,code,version,action,from_status,to_status,result,message,created_at FROM mc_extension_lifecycle_event ORDER BY id DESC LIMIT ' . $limit
            );
        } catch (\Throwable) {
            return [];
        }
    }

    public function activeThemeStylesheet(): ?string
    {
        try {
            $row = $this->connection->fetchAssociative("SELECT code,version FROM mc_extension_installation WHERE extension_type='theme' AND status='active' ORDER BY activated_at DESC,id DESC LIMIT 1");
            if (!is_array($row)) {
                return null;
            }
            $relative = 'extensions/' . rawurlencode((string) $row['code']) . '/' . rawurlencode((string) $row['version']) . '/theme.css';
            $absolute = rtrim($this->projectDir, '/\\') . '/public/media/' . str_replace('%2F', '/', $relative);
            return is_file($absolute) ? '/media/' . $relative : null;
        } catch (\Throwable) {
            return null;
        }
    }


    /** @param list<string> $files */
    private function extractTrustedPackage(string $archivePath, string $code, string $version, array $files): string
    {
        $base = rtrim($this->projectDir, '/\\') . '/var/extensions/installed/' . $code;
        if (!is_dir($base) && !@mkdir($base, 0750, true) && !is_dir($base)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.2919bff459e8'));
        }
        $final = $base . '/' . $version;
        if (is_dir($final)) {
            return $final;
        }
        $staging = $base . '/.staging-' . $version . '-' . bin2hex(random_bytes(6));
        if (!@mkdir($staging, 0750, true) && !is_dir($staging)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.560877e236c6'));
        }
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            $this->removeTree($staging);
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.f82499226bf4'));
        }
        try {
            foreach ($files as $name) {
                $target = $staging . '/' . $name;
                $directory = dirname($target);
                if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.e752215096ef'));
                }
                $stream = $zip->getStream($name);
                if (!is_resource($stream)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.20d14dc01b90') . $name);
                }
                $out = @fopen($target, 'wb');
                if (!is_resource($out)) {
                    fclose($stream);
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.04833c61aa8b') . $name);
                }
                stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);
                @chmod($target, 0640);
            }
        } catch (\Throwable $e) {
            $zip->close();
            $this->removeTree($staging);
            throw $e;
        }
        $zip->close();
        if (!@rename($staging, $final)) {
            $this->removeTree($staging);
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.516d2495cfd5'));
        }
        return $final;
    }


    /** @param array<string,mixed> $manifest */
    private function syncDeclaredPermissions(array $manifest): void
    {
        foreach ((array) ($manifest['permissions'] ?? []) as $permission) {
            if (!is_string($permission) || $permission === '') {
                continue;
            }
            $exists = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM mc_admin_permission WHERE code=?', [$permission]);
            if ($exists > 0) {
                continue;
            }
            $this->connection->insert('mc_admin_permission', [
                'code' => $permission,
                'description' => 'Extension permission: ' . $permission,
                'risk_level' => str_contains($permission, '.manage') || str_contains($permission, '.delete') ? 'high' : 'medium',
            ]);
        }
    }

    /** @param array<string,mixed> $manifest */
    private function publishDeclaredAssets(string $code, string $version, string $source, array $manifest): void
    {
        $assets = (array) ($manifest['assets'] ?? []);
        if ($assets === []) {
            return;
        }
        $targetBase = rtrim($this->projectDir, '/\\') . '/public/media/extensions/' . $code . '/' . $version;
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $relative = str_replace('\\', '/', (string) ($asset['path'] ?? ''));
            if ($relative === '' || preg_match('#(^|/)\.\.(?:/|$)#', $relative) === 1) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.974ef68ce2a9'));
            }
            $from = realpath($source . '/' . $relative);
            $root = realpath($source);
            if ($from === false || $root === false || !str_starts_with($from, $root . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.000b3be3dd14') . $relative);
            }
            $to = $targetBase . '/' . $relative;
            $dir = dirname($to);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.1f025fa3165f'));
            }
            if (!@copy($from, $to)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.a9afdd9afe9b') . $relative);
            }
            @chmod($to, 0644);
        }
    }

    /** @param list<string> $files */
    private function extractDeclarativePackage(string $archivePath, string $code, string $version, array $files): string
    {
        $base = rtrim($this->projectDir, '/\\') . '/var/extensions/installed/' . $code;
        if (!is_dir($base) && !@mkdir($base, 0750, true) && !is_dir($base)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.fe490114145c'));
        }
        $final = $base . '/' . $version;
        if (is_dir($final)) {
            return $final;
        }
        $staging = $base . '/.staging-' . $version . '-' . bin2hex(random_bytes(6));
        if (!@mkdir($staging, 0750, true) && !is_dir($staging)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ddb86121325d'));
        }

        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            $this->removeTree($staging);
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.51c542554876'));
        }
        try {
            foreach ($files as $name) {
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (in_array($extension, ['php','phtml','phar','js','mjs','cjs','sh','exe','dll','so','dylib','bat','cmd','ps1','jar','py','pl','rb'], true)) {
                    continue;
                }
                $target = $staging . '/' . $name;
                $directory = dirname($target);
                if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.419866b4d16b'));
                }
                $stream = $zip->getStream($name);
                if (!is_resource($stream)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.32b624f47d10') . $name);
                }
                $out = @fopen($target, 'wb');
                if (!is_resource($out)) {
                    fclose($stream);
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.93291b3a9d48') . $name);
                }
                stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);
                @chmod($target, 0640);
            }
        } catch (\Throwable $e) {
            $zip->close();
            $this->removeTree($staging);
            throw $e;
        }
        $zip->close();
        if (!@rename($staging, $final)) {
            $this->removeTree($staging);
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.bf5a62033dd6'));
        }
        return $final;
    }

    private function quarantineArchive(string $archivePath, string $code, string $version): string
    {
        $directory = rtrim($this->projectDir, '/\\') . '/var/extensions/quarantine/' . $code;
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.deee1095ed6b'));
        }
        $target = $directory . '/' . $version . '-' . bin2hex(random_bytes(6)) . '.zip';
        if (!@copy($archivePath, $target)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.1ec3490c4419'));
        }
        @chmod($target, 0600);
        return $target;
    }

    private function publishThemeAssets(string $code, string $version, string $source): void
    {
        $css = $source . '/theme.css';
        if (!is_file($css)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.267438a99479'));
        }
        $targetBase = rtrim($this->projectDir, '/\\') . '/public/media/extensions/' . $code;
        if (!is_dir($targetBase) && !@mkdir($targetBase, 0755, true) && !is_dir($targetBase)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.76a9f8b71ee7'));
        }
        $final = $targetBase . '/' . $version;
        $staging = $targetBase . '/.staging-' . $version . '-' . bin2hex(random_bytes(6));
        $this->copySafeThemeTree($source, $staging);
        if (is_dir($final)) {
            $this->removeTree($final);
        }
        if (!@rename($staging, $final)) {
            $this->removeTree($staging);
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.4f77bb87a68b'));
        }
    }

    private function copySafeThemeTree(string $source, string $target): void
    {
        if (!@mkdir($target, 0755, true) && !is_dir($target)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.38c200919001'));
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($source) + 1));
            $destination = $target . '/' . $relative;
            if ($item->isDir()) {
                if (!is_dir($destination) && !@mkdir($destination, 0755, true) && !is_dir($destination)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.21ef6deda4de'));
                }
                continue;
            }
            $extension = strtolower(pathinfo($destination, PATHINFO_EXTENSION));
            if (!in_array($extension, ['css','png','jpg','jpeg','webp','avif','gif','woff','woff2'], true)) {
                continue;
            }
            if (!@copy($item->getPathname(), $destination)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.316d8cc55c05'));
            }
            @chmod($destination, 0644);
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }

    private function recordActivationFailure(int $id, int $previousFailures, string $message): void
    {
        try {
            $this->connection->update('mc_extension_installation', [
                'failure_count' => $previousFailures + 1,
                'last_error' => mb_substr($message, 0, 1000, 'UTF-8'),
            ], ['id' => $id]);
        } catch (\Throwable) {
            // Failure accounting is diagnostic only and must not become another failure path.
        }
    }

    private function recordLifecycle(?int $installationId, string $code, string $version, string $action, ?string $fromStatus, ?string $toStatus, string $result, ?string $message): void
    {
        try {
            if (!$this->connection->createSchemaManager()->tablesExist(['mc_extension_lifecycle_event'])) {
                return;
            }
            $this->connection->insert('mc_extension_lifecycle_event', [
                'installation_id' => $installationId,
                'code' => mb_substr($code, 0, 96, 'UTF-8'),
                'version' => mb_substr($version, 0, 32, 'UTF-8'),
                'action' => mb_substr($action, 0, 32, 'UTF-8'),
                'from_status' => $fromStatus !== null ? mb_substr($fromStatus, 0, 24, 'UTF-8') : null,
                'to_status' => $toStatus !== null ? mb_substr($toStatus, 0, 24, 'UTF-8') : null,
                'result' => mb_substr($result, 0, 24, 'UTF-8'),
                'message' => $message !== null ? mb_substr(preg_replace('/[\r\n\t]+/', ' ', $message) ?: $message, 0, 1000, 'UTF-8') : null,
                'created_at' => $this->now(),
            ]);
        } catch (\Throwable) {
            // Lifecycle logging is diagnostic and must never block extension operations.
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
