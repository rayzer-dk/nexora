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
            'core' => '^3.4.8',
            'extension_api' => PlatformVersion::EXTENSION_API,
            'permissions' => [],
            'execution' => 'declarative',
        ], JSON_THROW_ON_ERROR)]];
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
    private function archive(array $files, bool $withManifest = true): string
    {
        $manifest = [
            'code' => 'acme.test_theme',
            'name' => 'Test theme',
            'version' => '1.0.0',
            'type' => 'theme',
            'core' => '^3.4.8',
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
