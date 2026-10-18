<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

use Doctrine\DBAL\Connection;

/**
 * Runtime view of active extension contributions.
 *
 * The registry is intentionally manifest-driven: disabling or rolling back an
 * extension changes the active contribution set without modifying Core files.
 */
final class ExtensionContributionRegistry
{
    /** Module field types the builder can edit; `secret` is left out because a layout is printed on the storefront. */
    private const BLOCK_FIELD_TYPES = ['text' => 'text', 'textarea' => 'textarea', 'url' => 'url', 'email' => 'text', 'number' => 'number', 'integer' => 'number', 'range' => 'number', 'select' => 'select', 'boolean' => 'boolean', 'color' => 'color', 'multilingual_text' => 'multilingual_text', 'multilingual_textarea' => 'multilingual_textarea'];

    /** @var list<array<string,mixed>>|null */
    private ?array $manifests = null;

    /** @var array<string,list<array<string,mixed>>|null> */
    private array $blockFields = [];

    public function __construct(private readonly Connection $connection, private readonly ExtensionSettingsSchemaValidator $schemas = new ExtensionSettingsSchemaValidator())
    {
    }

    /** @return list<array<string,mixed>> */
    public function activeManifests(): array
    {
        if ($this->manifests !== null) {
            return $this->manifests;
        }
        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT id,code,version,install_path,manifest_json FROM mc_extension_installation WHERE status='active' ORDER BY code,id"
            );
        } catch (\Throwable) {
            return $this->manifests = [];
        }
        $out = [];
        foreach ($rows as $row) {
            try {
                $manifest = json_decode((string) ($row['manifest_json'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }
            if (!is_array($manifest)) {
                continue;
            }
            $manifest['_runtime'] = [
                'installation_id' => (int) ($row['id'] ?? 0),
                'code' => (string) ($row['code'] ?? ''),
                'version' => (string) ($row['version'] ?? ''),
                'install_path' => (string) ($row['install_path'] ?? ''),
            ];
            $out[] = $manifest;
        }
        return $this->manifests = $out;
    }

    /** @return list<array<string,mixed>> */
    public function blocksFor(string $layoutType): array
    {
        $out = [];
        foreach ($this->activeManifests() as $manifest) {
            foreach ((array) ($manifest['blocks'] ?? []) as $block) {
                if (!is_array($block) || (string) ($block['surface'] ?? '') !== $layoutType) {
                    continue;
                }
                $out[] = $block + ['extension_code' => (string) ($manifest['code'] ?? '')];
            }
        }
        return $out;
    }

    public function hasBuilderComponent(string $component, ?string $layoutType = null): bool
    {
        foreach ($this->activeManifests() as $manifest) {
            foreach ((array) ($manifest['blocks'] ?? []) as $block) {
                if (!is_array($block) || (string) ($block['id'] ?? '') !== $component) {
                    continue;
                }
                if ($layoutType === null || (string) ($block['surface'] ?? '') === $layoutType) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array<string,mixed>|null */
    public function block(string $component): ?array
    {
        foreach ($this->activeManifests() as $manifest) {
            foreach ((array) ($manifest['blocks'] ?? []) as $block) {
                if (is_array($block) && (string) ($block['id'] ?? '') === $component) {
                    return $block + ['extension_code' => (string) ($manifest['code'] ?? '')];
                }
            }
        }
        return null;
    }

    /**
     * Builder fields of a module block, read from its `settings_schema` in the shape of BuilderComponentCatalog
     * (`prop` = `props.<key>`, `label` is the module's own text). [] when the block declares no schema; null for an
     * unknown block or a schema that cannot be used, which is written once to the extension log.
     *
     * @return list<array<string,mixed>>|null
     */
    public function blockFields(string $component): ?array
    {
        if (!array_key_exists($component, $this->blockFields)) {
            $this->blockFields[$component] = $this->readBlockFields($component);
        }

        return $this->blockFields[$component];
    }

    /** @return list<array<string,mixed>>|null */
    private function readBlockFields(string $component): ?array
    {
        foreach ($this->activeManifests() as $manifest) {
            foreach ((array) ($manifest['blocks'] ?? []) as $block) {
                if (!is_array($block) || (string) ($block['id'] ?? '') !== $component) {
                    continue;
                }
                if (!isset($block['settings_schema'])) {
                    return [];
                }
                $runtime = (array) $manifest['_runtime'];
                try {
                    return $this->builderFields($this->schemas->decodeAndValidate($this->schemaSource((string) $runtime['install_path'], (string) $block['settings_schema'])));
                } catch (\RuntimeException $e) {
                    $this->logInvalidSchema($runtime, $component . ': ' . $e->getMessage());

                    return null;
                }
            }
        }

        return null;
    }

    private function schemaSource(string $installPath, string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        $path = rtrim($installPath, '/\\') . '/' . $relative;
        if ($relative === '' || str_starts_with($relative, '/') || preg_match('#(^|/)\.\.(?:/|$)#', $relative) === 1 || !is_file($path)) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a16cf4d39720') . ' ' . $relative);
        }

        return (string) file_get_contents($path);
    }

    /** @param array{schema_version:int,fields:list<array<string,mixed>>} $schema @return list<array<string,mixed>> */
    private function builderFields(array $schema): array
    {
        $out = [];
        foreach ($schema['fields'] as $field) {
            $type = self::BLOCK_FIELD_TYPES[(string) $field['type']] ?? null;
            if ($type === null) {
                continue;
            }
            $builderField = ['key' => (string) $field['key'], 'type' => $type, 'label' => (string) $field['label'], 'group' => 'builder_module_settings', 'prop' => 'props.' . $field['key']];
            foreach (['help', 'default', 'min', 'max', 'step', 'options', 'placeholder'] as $property) {
                if (isset($field[$property]) && $field[$property] !== '') {
                    $builderField[$property] = $field[$property];
                }
            }
            if ((string) $field['type'] === 'integer') {
                $builderField['step'] ??= 1;
            }
            $out[] = $builderField;
        }

        return $out;
    }

    /** @param array<mixed> $runtime */
    private function logInvalidSchema(array $runtime, string $message): void
    {
        $message = mb_substr($message, 0, 1000, 'UTF-8');
        $event = ['installation_id' => (int) ($runtime['installation_id'] ?? 0), 'code' => (string) ($runtime['code'] ?? ''), 'version' => (string) ($runtime['version'] ?? ''), 'action' => 'block_schema_invalid'];
        $logged = $this->connection->fetchOne('SELECT 1 FROM mc_extension_lifecycle_event WHERE installation_id=? AND code=? AND version=? AND action=? AND message=? LIMIT 1', [...array_values($event), $message]);
        if ($logged === false) {
            $this->connection->insert('mc_extension_lifecycle_event', $event + ['from_status' => null, 'to_status' => null, 'result' => 'error', 'message' => $message, 'created_at' => gmdate('Y-m-d H:i:s')]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function slotContributions(string $slot): array
    {
        $out = [];
        foreach ($this->activeManifests() as $manifest) {
            foreach ((array) ($manifest['slot_contributions'] ?? []) as $contribution) {
                if (!is_array($contribution) || (string) ($contribution['slot'] ?? '') !== $slot) {
                    continue;
                }
                $out[] = $contribution + ['extension_code' => (string) ($manifest['code'] ?? '')];
            }
        }
        usort($out, static fn(array $a,array $b): int => ((int)($a['priority']??0)) <=> ((int)($b['priority']??0)));
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function routes(): array
    {
        $out = [];
        foreach ($this->activeManifests() as $manifest) {
            foreach ((array) ($manifest['routes'] ?? []) as $route) {
                if (is_array($route)) {
                    $out[] = $route + ['extension_code' => (string) ($manifest['code'] ?? '')];
                }
            }
        }
        return $out;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        $out = [];
        foreach ($this->activeManifests() as $manifest) {
            foreach ((array) ($manifest['permissions'] ?? []) as $permission) {
                if (is_string($permission) && $permission !== '') {
                    $out[$permission] = true;
                }
            }
        }
        return array_keys($out);
    }

    /** @return list<array<string,mixed>> */
    public function assetsFor(string $scope): array
    {
        $out = [];
        foreach ($this->activeManifests() as $manifest) {
            $runtime = is_array($manifest['_runtime'] ?? null) ? $manifest['_runtime'] : [];
            foreach ((array) ($manifest['assets'] ?? []) as $asset) {
                if (!is_array($asset) || !in_array($scope, (array) ($asset['scopes'] ?? []), true)) {
                    continue;
                }
                $out[] = $asset + [
                    'extension_code' => (string) ($manifest['code'] ?? ''),
                    'version' => (string) ($runtime['version'] ?? ''),
                    'install_path' => (string) ($runtime['install_path'] ?? ''),
                ];
            }
        }
        return $out;
    }
}
