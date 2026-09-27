<?php

declare(strict_types=1);

namespace Commerce\Core\Update;

use Doctrine\DBAL\Connection;

final readonly class ActiveExtensionCompatibilityInspector
{
    public function __construct(private Connection $connection) {}

    /** @return list<string> */
    public function validateTarget(string $targetCoreVersion, string $targetExtensionApi): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT code,version,api_version,manifest_json FROM mc_extension_installation WHERE status='active' ORDER BY code,id"
            );
        } catch (\Throwable) {
            return [];
        }

        $errors = [];
        foreach ($rows as $row) {
            try {
                $manifest = json_decode((string) ($row['manifest_json'] ?? '{}'), true, 64, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $errors[] = sprintf('%s %s: manifest cannot be read.', (string) $row['code'], (string) $row['version']);
                continue;
            }
            if (!is_array($manifest)) {
                continue;
            }
            $extensionApi = (string) ($manifest['extension_api'] ?? $row['api_version'] ?? '');
            if ($extensionApi !== $targetExtensionApi) {
                $errors[] = sprintf('%s %s requires Extension API %s; update provides %s.', (string) $row['code'], (string) $row['version'], $extensionApi, $targetExtensionApi);
                continue;
            }
            $constraint = trim((string) ($manifest['core'] ?? ''));
            if (!$this->supportsCore($targetCoreVersion, $constraint)) {
                $errors[] = sprintf('%s %s does not declare compatibility with Core %s (%s).', (string) $row['code'], (string) $row['version'], $targetCoreVersion, $constraint !== '' ? $constraint : 'no constraint');
            }
        }
        return $errors;
    }

    private function supportsCore(string $version, string $constraint): bool
    {
        if (preg_match('/^(\^|~|>=)?\s*(\d+)\.(\d+)\.(\d+)$/D', $constraint, $m) !== 1) {
            return false;
        }
        $operator = $m[1] ?? '';
        $base = $m[2] . '.' . $m[3] . '.' . $m[4];
        if ($operator === '') return version_compare($version, $base, '==');
        if ($operator === '>=') return version_compare($version, $base, '>=');
        if (version_compare($version, $base, '<')) return false;
        $major=(int)$m[2];$minor=(int)$m[3];$patch=(int)$m[4];
        if ($operator === '~') $upper=$major.'.'.($minor+1).'.0';
        elseif ($major>0) $upper=($major+1).'.0.0';
        elseif ($minor>0) $upper='0.'.($minor+1).'.0';
        else $upper='0.0.'.($patch+1);
        return version_compare($version, $upper, '<');
    }
}
