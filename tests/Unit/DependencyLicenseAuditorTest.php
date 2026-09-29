<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Licensing\DependencyLicenseAuditor;
use PHPUnit\Framework\TestCase;

final class DependencyLicenseAuditorTest extends TestCase
{
    public function testPackageAuditChecksDistributedDependenciesAndPreservesNames(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nexora-license-');
        if ($path === false) {
            self::fail('Could not create a temporary package lock file.');
        }

        try {
            file_put_contents($path, json_encode([
                'packages' => [
                    '' => ['name' => 'nexora-commerce-ui', 'license' => 'MIT'],
                    'node_modules/dev-only' => ['dev' => true, 'license' => 'UNKNOWN'],
                    'node_modules/minimatch' => ['license' => 'UNKNOWN'],
                ],
            ], JSON_THROW_ON_ERROR));

            $report = (new DependencyLicenseAuditor())->auditPackageLock($path);
            self::assertFalse($report['ok']);
            self::assertSame(1, $report['checked']);
            self::assertSame([['name' => 'minimatch', 'license' => 'UNKNOWN']], $report['violations']);
        } finally {
            unlink($path);
        }
    }
}
