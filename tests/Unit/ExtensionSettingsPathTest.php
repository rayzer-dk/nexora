<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Extension\ExtensionSettingsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtensionSettingsPathTest extends TestCase
{
    /** @return iterable<string,array{string,bool}> */
    public static function paths(): iterable
    {
        yield 'plain file' => ['settings.schema.json', true];
        yield 'nested file' => ['config/settings.schema.json', true];
        yield 'dots in a name' => ['config/settings..schema.json', true];
        yield 'empty' => ['', false];
        yield 'absolute' => ['/etc/passwd', false];
        yield 'parent' => ['../settings.json', false];
        yield 'parent in the middle' => ['a/../b.json', false];
        yield 'parent at the end' => ['a/..', false];
    }

    #[DataProvider('paths')]
    public function testOnlyAFileInsideTheModuleFolderIsAccepted(string $path, bool $safe): void
    {
        self::assertSame($safe, ExtensionSettingsManager::isSafeRelativePath($path));
    }
}
