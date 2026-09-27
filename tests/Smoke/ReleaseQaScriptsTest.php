<?php

declare(strict_types=1);

namespace Commerce\Tests\Smoke;

use PHPUnit\Framework\TestCase;

final class ReleaseQaScriptsTest extends TestCase
{
    public function testCriticalQaScriptsExist(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['admin-access-check.php','license-audit.php','twig-syntax-check.php','full-release-static-check.php'] as $file) {
            self::assertFileExists($root . '/bin/' . $file);
        }
    }
}
