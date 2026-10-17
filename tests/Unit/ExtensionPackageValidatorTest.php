<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Extension\ExtensionPackageValidator;
use Commerce\Core\Extension\ExtensionSettingsSchemaValidator;
use Commerce\Core\Extension\TrustedExtensionSignatureVerifier;
use Commerce\Core\Platform\PlatformVersion;
use Commerce\Core\Security\OutboundUrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class ExtensionPackageValidatorTest extends TestCase
{
    /** @var list<string> */
    private array $archives = [];

    protected function tearDown(): void
    {
        foreach ($this->archives as $archive) {
            @unlink($archive);
        }
    }

    public function testDeclarativeThemeIsAccepted(): void
    {
        $inspection = $this->validator()->inspect($this->archive(['theme.css' => 'a{color:red}']), false);

        self::assertFalse($inspection->quarantined);
    }

    public function testExecutableCodeWithoutTrustedSignatureIsQuarantinedNotInstalled(): void
    {
        $inspection = $this->validator()->inspect($this->archive(['src/Handler.php' => '<?php']), false);

        self::assertTrue($inspection->quarantined);
    }

    /** @param array<string,string> $files */
    #[DataProvider('maliciousPackages')]
    public function testMaliciousPackageIsRejected(array $files): void
    {
        $this->expectException(RuntimeException::class);
        $this->validator()->inspect($this->archive($files), false);
    }

    /** @return iterable<string,array{array<string,string>}> */
    public static function maliciousPackages(): iterable
    {
        yield 'zip slip' => [['../evil.json' => '{}']];
        yield 'zip slip with backslashes' => [['..\\evil.json' => '{}']];
        yield 'absolute path' => [['/etc/evil.json' => '{}']];
        yield 'phar' => [['payload.phar' => 'x']];
        yield 'hidden file' => [['.htaccess' => 'x']];
        yield 'remote css import' => [['theme.css' => '@import url(http://example.com/x.css);']];
        yield 'admin template override' => [['templates/admin/a.twig' => 'x']];
        yield 'path traversal in code' => [['manifest.json' => json_encode([
            'code' => '../x',
            'name' => 'Test theme',
            'version' => '1.0.0',
            'type' => 'theme',
            'core' => '^3.4.8 || ^4.0',
            'extension_api' => PlatformVersion::EXTENSION_API,
            'permissions' => [],
            'execution' => 'declarative',
        ], JSON_THROW_ON_ERROR)]];
    }

    /** The platform is 4.x: a Composer-style OR keeps 3.x modules installable once their authors add the 4.x branch. */
    #[DataProvider('supportedCoreConstraints')]
    public function testCoreConstraintThatIncludesTheRunningCoreIsAccepted(string $core): void
    {
        $inspection = $this->validator()->inspect($this->archive(['theme.css' => 'a{}'], core: $core), false);

        self::assertSame($core, $inspection->manifest['core']);
    }

    /** @return iterable<string,array{string}> */
    public static function supportedCoreConstraints(): iterable
    {
        yield 'or with major-minor caret' => ['^3.4.8 || ^4.0'];
        yield 'or without spaces' => ['^3.4.8||^4.0.0'];
        yield 'second branch first' => ['^4.0 || ^3.4.8'];
        yield 'three branches' => ['~2.1.0 || ^3.4.8 || ^4.0'];
        yield 'caret on the running major' => ['^4.0.0'];
        yield 'lower bound' => ['>=3.0.0'];
    }

    /** A bare ^3 must not silently mean 4.x, and a malformed OR is not "any version". */
    #[DataProvider('unsupportedCoreConstraints')]
    public function testCoreConstraintThatExcludesTheRunningCoreIsRejected(string $core): void
    {
        $this->expectException(RuntimeException::class);
        $this->validator()->inspect($this->archive(['theme.css' => 'a{}'], core: $core), false);
    }

    /** @return iterable<string,array{string}> */
    public static function unsupportedCoreConstraints(): iterable
    {
        yield 'only 3.x' => ['^3.4.8'];
        yield 'only 3.x major-minor' => ['^3.4'];
        yield 'or without the running major' => ['^3.4.8 || ^5.0'];
        yield 'dangling or' => ['^3.4.8 ||'];
        yield 'leading or' => ['|| ^4.0'];
        yield 'single pipe' => ['^3.4.8 | ^4.0'];
        yield 'empty branch' => ['^3.4.8 |||| ^4.0'];
        yield 'garbage branch' => ['^3.4.8 || latest'];
    }

    public function testArchiveWithoutManifestIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->validator()->inspect($this->archive(['theme.css' => 'a{}'], withManifest: false), false);
    }

    private function validator(): ExtensionPackageValidator
    {
        return new ExtensionPackageValidator(
            new ExtensionSettingsSchemaValidator(),
            new OutboundUrlPolicy(),
            new TrustedExtensionSignatureVerifier(sys_get_temp_dir()),
        );
    }

    /** @param array<string,string> $files */
    private function archive(array $files, bool $withManifest = true, string $core = '^3.4.8 || ^4.0'): string
    {
        $manifest = [
            'code' => 'acme.test_theme',
            'name' => 'Test theme',
            'version' => '1.0.0',
            'type' => 'theme',
            'core' => $core,
            'extension_api' => PlatformVersion::EXTENSION_API,
            'permissions' => [],
            'execution' => 'declarative',
        ];
        if ($withManifest && !isset($files['manifest.json'])) {
            $files['manifest.json'] = json_encode($manifest, JSON_THROW_ON_ERROR);
        }
        $path = tempnam(sys_get_temp_dir(), 'nexora-ext-');
        if ($path === false) {
            self::fail('Could not create a temporary archive.');
        }
        $this->archives[] = $path;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }
}
