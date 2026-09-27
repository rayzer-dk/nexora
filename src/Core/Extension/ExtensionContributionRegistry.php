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
    /** @var list<array<string,mixed>>|null */
    private ?array $manifests = null;

    public function __construct(private readonly Connection $connection)
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
                "SELECT code,version,install_path,manifest_json FROM mc_extension_installation WHERE status='active' ORDER BY code,id"
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
