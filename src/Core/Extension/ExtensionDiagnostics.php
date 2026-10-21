<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

use Commerce\Core\Platform\PlatformVersion;
use DomainException;
use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Read-only health card of one installed module: version, core compatibility, signature, permissions,
 * migrations, settings schema and the latest lifecycle events. Nothing here changes module state.
 */
final readonly class ExtensionDiagnostics
{
    public function __construct(
        private Connection $connection,
        private ExtensionMigrationRunner $migrations,
        private ExtensionSettingsSchemaValidator $schemas,
        private ExtensionPackageManager $packages,
    ) {
    }

    /**
     * @return array{
     *   extension:array<string,mixed>,
     *   core:array{constraint:string,platform:string,compatible:?bool},
     *   signature:array{state:string,publisher:string,key_id:string},
     *   permissions:list<string>,
     *   migrations:list<array{key:string,applied:bool,changed:bool}>,
     *   settings:array{state:string,fields:int,message:string},
     *   events:list<array<string,mixed>>
     * }
     */
    public function for(int $installationId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id,code,name,version,extension_type,api_version,status,failure_count,last_error,install_path,manifest_json FROM mc_extension_installation WHERE id=? LIMIT 1',
            [$installationId]
        );
        if (!is_array($row)) {
            throw new DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extension_installation_not_found'));
        }
        try {
            $manifest = json_decode((string) $row['manifest_json'], true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $manifest = [];
        }
        $manifest = is_array($manifest) ? $manifest : [];
        $installPath = (string) $row['install_path'];
        $constraint = (string) ($manifest['core'] ?? '');
        $publisher = is_array($manifest['publisher'] ?? null) ? $manifest['publisher'] : [];
        $trusted = (string) ($manifest['execution'] ?? '') === 'trusted_release';

        return [
            'extension' => [
                'id' => (int) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name'], 'version' => (string) $row['version'],
                'type' => (string) $row['extension_type'], 'api_version' => (string) $row['api_version'], 'status' => (string) $row['status'],
                'execution' => (string) ($manifest['execution'] ?? 'declarative'), 'failure_count' => (int) $row['failure_count'], 'last_error' => (string) ($row['last_error'] ?? ''),
            ],
            'core' => [
                'constraint' => $constraint,
                'platform' => PlatformVersion::VERSION,
                'compatible' => $constraint === '' ? null : CoreVersionConstraint::allows($constraint, PlatformVersion::VERSION),
            ],
            'signature' => [
                'state' => (string) $row['status'] === 'quarantined' ? 'quarantined' : ($trusted ? 'trusted' : 'not_required'),
                'publisher' => (string) ($publisher['name'] ?? ''),
                'key_id' => (string) ($publisher['key_id'] ?? ''),
            ],
            'permissions' => array_values(array_filter(array_map('strval', (array) ($manifest['permissions'] ?? [])))),
            'migrations' => $this->migrations->status((int) $row['id'], $installPath, $manifest),
            'settings' => $this->settings($installPath, $manifest),
            'events' => $this->packages->lifecycle(10, (string) $row['code']),
        ];
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array{state:string,fields:int,message:string}
     */
    private function settings(string $installPath, array $manifest): array
    {
        if (!isset($manifest['settings_schema'])) {
            return ['state' => 'none', 'fields' => 0, 'message' => ''];
        }
        $relative = str_replace('\\', '/', (string) $manifest['settings_schema']);
        $file = ExtensionSettingsManager::isSafeRelativePath($relative)
            ? rtrim($installPath, '/\\') . '/' . $relative
            : false;
        if ($file === false || !is_file($file)) {
            return ['state' => 'error', 'fields' => 0, 'message' => $relative];
        }
        try {
            $schema = $this->schemas->decodeAndValidate((string) file_get_contents($file));
        } catch (Throwable $e) {
            return ['state' => 'error', 'fields' => 0, 'message' => mb_substr($e->getMessage(), 0, 300, 'UTF-8')];
        }

        return ['state' => 'ok', 'fields' => count($schema['fields']), 'message' => ''];
    }
}
