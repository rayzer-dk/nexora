<?php

declare(strict_types=1);

namespace Commerce\Core\Licensing;

use RuntimeException;

final readonly class DependencyLicenseAuditor
{
    public function __construct(private DependencyLicensePolicy $policy = new DependencyLicensePolicy())
    {
    }

    /** @return array{ok:bool,checked:int,violations:list<array{name:string,license:string}>} */
    public function auditComposerLock(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => true, 'checked' => 0, 'violations' => []];
        }
        $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        // The production archive installs Composer with --no-dev.
        $packages = (array) ($data['packages'] ?? []);
        $violations = [];
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }
            $licenses = (array) ($package['license'] ?? []);
            if ($licenses === []) {
                $violations[] = ['name' => (string) ($package['name'] ?? 'unknown'), 'license' => 'UNKNOWN'];
                continue;
            }
            $ok = false;
            foreach ($licenses as $license) {
                if (is_string($license) && $this->policy->isAllowed($license)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $violations[] = ['name' => (string) ($package['name'] ?? 'unknown'), 'license' => implode(' OR ', array_map('strval', $licenses))];
            }
        }
        return ['ok' => $violations === [], 'checked' => count($packages), 'violations' => $violations];
    }

    /** @return array{ok:bool,checked:int,violations:list<array{name:string,license:string}>} */
    public function auditPackageLock(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => true, 'checked' => 0, 'violations' => []];
        }
        $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $packages = (array) ($data['packages'] ?? []);
        $violations = [];
        $checked = 0;
        foreach ($packages as $name => $package) {
            if ($name === '' || !is_array($package) || !empty($package['dev'])) {
                continue; // development-only tooling is not redistributed
            }
            ++$checked;
            $license = trim((string) ($package['license'] ?? ''));
            if (!$this->policy->isAllowed($license)) {
                $violations[] = ['name' => (string) preg_replace('#^.*node_modules/#', '', (string) $name), 'license' => $license !== '' ? $license : 'UNKNOWN'];
            }
        }
        return ['ok' => $violations === [], 'checked' => $checked, 'violations' => $violations];
    }

    public function assertRedistributable(string $composerLock, string $packageLock): void
    {
        $composer = $this->auditComposerLock($composerLock);
        $npm = $this->auditPackageLock($packageLock);
        $violations = array_merge($composer['violations'], $npm['violations']);
        if ($violations !== []) {
            $first = $violations[0];
            throw new RuntimeException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.dbf44a348e48'), $first['name'], $first['license']));
        }
    }
}
