<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TrustedMigrationContractTest extends TestCase
{
    public function testDeveloperDocumentationRequiresReversibleMigrations(): void
    {
        $root = dirname(__DIR__, 2);
        $guide = (string) file_get_contents($root . '/docs/DEVELOPER_GUIDE.md');
        self::assertStringContainsString("'up'", $guide);
        self::assertStringContainsString("'down'", $guide);
        self::assertStringContainsString('reversible', strtolower($guide));
    }
}
