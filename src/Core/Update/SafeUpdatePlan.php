<?php

declare(strict_types=1);
namespace Commerce\Core\Update;

final class SafeUpdatePlan
{
    /** @return list<string> */
    public function phases(): array
    {
        return [
            'verify-signed-manifest',
            'verify-package-sha256',
            'runtime-and-database-preflight',
            'extension-compatibility-check',
            'maintenance-drain-write-jobs',
            'database-backup-checkpoint',
            'files-release-checkpoint',
            'unpack-to-new-release-directory',
            'composer-platform-validation',
            'database-migrations',
            'cache-warmup',
            'smoke-and-health-checks',
            'atomic-release-switch',
            'resume-workers',
            'post-update-monitoring',
        ];
    }

    public function rollbackPhases(): array
    {
        return ['maintenance-drain-write-jobs','restore-database-checkpoint','atomic-release-switch-back','cache-warmup','resume-workers','health-check'];
    }
}
