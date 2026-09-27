<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

use Commerce\Core\I18n\TranslationCatalogLoader;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Routing\RouterInterface;

final class ExtensionConflictGuard
{
    public function __construct(private readonly Connection $connection, private readonly RouterInterface $router)
    {
    }

    /** @param array<string,mixed> $manifest */
    public function assertInstallable(array $manifest): void
    {
        $code = (string) ($manifest['code'] ?? '');
        $namespace = TranslationCatalogLoader::namespaceForCode($code);
        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT code,manifest_json FROM mc_extension_installation WHERE code<>? AND status IN ('active','staged','disabled')",
                [$code]
            );
        } catch (\Throwable) {
            return;
        }

        $claimedBlocks = $this->ids((array) ($manifest['blocks'] ?? []), 'id');
        $claimedRoutes = $this->ids((array) ($manifest['routes'] ?? []), 'name');
        $claimedPaths = $this->ids((array) ($manifest['routes'] ?? []), 'path');
        $claimedPermissions = array_fill_keys(array_values(array_filter((array) ($manifest['permissions'] ?? []), 'is_string')), true);

        foreach (array_keys($claimedPaths) as $path) {
            try {
                $matched = $this->router->match($path);
                $route = (string) ($matched['_route'] ?? '');
                if ($route !== '' && !in_array($route, ['admin_extension_dynamic','storefront_seo_entity'], true)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.core_route_collision') . $route);
                }
            } catch (\Symfony\Component\Routing\Exception\ResourceNotFoundException|\Symfony\Component\Routing\Exception\MethodNotAllowedException) {
            }
        }

        foreach ($rows as $row) {
            $otherCode = (string) ($row['code'] ?? '');
            if ($otherCode !== '' && TranslationCatalogLoader::namespaceForCode($otherCode) === $namespace) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.72be79889ebe') . $otherCode . '.');
            }
            try {
                $other = json_decode((string) ($row['manifest_json'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }
            if (!is_array($other)) {
                continue;
            }
            foreach ($this->ids((array) ($other['blocks'] ?? []), 'id') as $id => $_) {
                if (isset($claimedBlocks[$id])) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.68b317ad2e4e') . $id);
                }
            }
            foreach ($this->ids((array) ($other['routes'] ?? []), 'name') as $id => $_) {
                if (isset($claimedRoutes[$id])) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.fe67677d38f3') . $id);
                }
            }
            foreach ($this->ids((array) ($other['routes'] ?? []), 'path') as $path => $_) {
                if (isset($claimedPaths[$path])) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.f45149048fc7') . $path);
                }
            }
            foreach ((array) ($other['permissions'] ?? []) as $permission) {
                if (is_string($permission) && isset($claimedPermissions[$permission])) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.5af3cf21f32b') . $permission);
                }
            }
        }
    }

    /** @param array<mixed> $rows @return array<string,true> */
    private function ids(array $rows, string $field): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row[$field] ?? null) && $row[$field] !== '') {
                $out[$row[$field]] = true;
            }
        }
        return $out;
    }
}
