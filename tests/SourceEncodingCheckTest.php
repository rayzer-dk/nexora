<?php

declare(strict_types=1);

namespace Commerce\Tests;

use Commerce\DevelopmentTools\SourceEncodingCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// phpcs:disable PSR1.Files.SideEffects -- Load the standalone CLI without executing its guarded entry point.
require_once dirname(__DIR__) . '/bin/source-encoding-check.php';

final class SourceEncodingCheckTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/nexora-encoding-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->directory);
    }

    public function testAcceptsUkrainianRussianAndUnicodeWithoutChangingText(): void
    {
        $text = "Українська: їжак, ґанок. Русский: проверка. € 😀 e\u{0301}\n";
        self::assertNull(SourceEncodingCheck::contentError($text));
        self::assertNull(SourceEncodingCheck::contentError(''));
        $this->write('src/translation.php', $text);

        self::assertSame(['checked' => 1, 'errors' => []], SourceEncodingCheck::check($this->directory));
        self::assertSame($text, file_get_contents($this->directory . '/src/translation.php'));
    }

    #[DataProvider('invalidContent')]
    public function testRejectsBomAndInvalidUtf8(string $content, string $expected): void
    {
        self::assertSame($expected, SourceEncodingCheck::contentError($content));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidContent(): iterable
    {
        yield 'UTF-8 BOM' => ["\xEF\xBB\xBF<?php\n", 'UTF-8 BOM is not allowed'];
        yield 'UTF-16 little endian' => ["\xFF\xFEh\x00", 'UTF-16/UTF-32 BOM is not allowed'];
        yield 'UTF-16 big endian' => ["\xFE\xFF\x00h", 'UTF-16/UTF-32 BOM is not allowed'];
        yield 'UTF-32 big endian' => ["\x00\x00\xFE\xFF", 'UTF-16/UTF-32 BOM is not allowed'];
        yield 'invalid continuation byte' => ["secret\xC3\x28", 'Invalid UTF-8'];
        yield 'truncated character' => ["\xD0", 'Invalid UTF-8'];
    }

    public function testScansSourceConfigDocsAndExtensionlessConsole(): void
    {
        foreach (
            ['bin/console', 'src/App.php', 'templates/a.twig', 'assets/a.ts', 'docs/a.md',
                'phpunit.xml.dist', 'composer.lock', '.editorconfig', '.github/workflows/qa.yml'] as $path
        ) {
            $this->write($path, "valid UTF-8: Україна\n");
        }
        $this->write('image.png', "\xFF\xFE");

        self::assertSame(['checked' => 9, 'errors' => []], SourceEncodingCheck::check($this->directory));
    }

    public function testDefaultScanExcludesDependenciesRuntimeAndGeneratedDirectories(): void
    {
        foreach (
            ['vendor', 'node_modules', '.git', 'var', '.autopilot', '.claude', '.codex',
                'public/build', 'public/media', 'nested/vendor'] as $directory
        ) {
            $this->write($directory . '/bad.php', "\xEF\xBB\xBF<?php\n");
        }
        $this->write('src/good.php', "<?php\n");

        self::assertSame(['checked' => 1, 'errors' => []], SourceEncodingCheck::check($this->directory));
    }

    public function testSelectedFilesAndDirectoriesAllowFocusedDiagnosticsAndDeduplicate(): void
    {
        $this->write('vendor/bad.php', "\xEF\xBB\xBFprivate content\n");
        $this->write('src/good.php', "<?php\n");

        $result = SourceEncodingCheck::check($this->directory, ['vendor', 'vendor/bad.php', 'src/good.php']);

        self::assertSame(2, $result['checked']);
        self::assertSame(['vendor/bad.php: UTF-8 BOM is not allowed'], $result['errors']);
        self::assertStringNotContainsString('private content', implode("\n", $result['errors']));
    }

    public function testMissingSelectedPathFailsInsteadOfSilentlyPassing(): void
    {
        self::assertSame(
            ['checked' => 0, 'errors' => ['src/missing.php: Path does not exist']],
            SourceEncodingCheck::check($this->directory, ['src/missing.php']),
        );
    }

    public function testInvalidFixtureFailsAndTheFileRemainsUnchanged(): void
    {
        $bytes = "<?php\n// secret value \xC3\x28\n";
        $this->write('src/bad.php', $bytes);
        $this->write('src/good.php', "<?php\n");

        self::assertSame(
            ['checked' => 2, 'errors' => ['src/bad.php: Invalid UTF-8']],
            SourceEncodingCheck::check($this->directory),
        );
        self::assertSame($bytes, file_get_contents($this->directory . '/src/bad.php'));
    }

    private function write(string $path, string $content): void
    {
        $absolute = $this->directory . '/' . $path;
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0777, true);
        }
        file_put_contents($absolute, $content);
    }
}
