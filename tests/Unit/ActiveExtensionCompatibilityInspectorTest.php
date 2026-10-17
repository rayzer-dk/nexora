<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Update\ActiveExtensionCompatibilityInspector;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/** The core update preflight must read the manifest "core" field exactly as the package validator does. */
final class ActiveExtensionCompatibilityInspectorTest extends TestCase
{
    public function testExtensionDeclaringTheNewMajorInAnOrBranchDoesNotBlockTheUpdate(): void
    {
        self::assertSame([], $this->inspector('^3.4.8 || ^4.0')->validateTarget('4.0.0', '2.0'));
    }

    public function testExtensionDeclaringOnlyTheOldMajorBlocksTheUpdate(): void
    {
        $errors = $this->inspector('^3.4.8')->validateTarget('4.0.0', '2.0');

        self::assertCount(1, $errors);
        self::assertStringContainsString('acme.hello 1.0.0 does not declare compatibility with Core 4.0.0 (^3.4.8)', $errors[0]);
    }

    public function testMalformedOrBlocksTheUpdate(): void
    {
        self::assertCount(1, $this->inspector('^3.4.8 ||')->validateTarget('4.0.0', '2.0'));
    }

    private function inspector(string $core): ActiveExtensionCompatibilityInspector
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([[
            'code' => 'acme.hello',
            'version' => '1.0.0',
            'api_version' => '2.0',
            'manifest_json' => json_encode(['extension_api' => '2.0', 'core' => $core], JSON_THROW_ON_ERROR),
        ]]);

        return new ActiveExtensionCompatibilityInspector($connection);
    }
}
