<?php

declare(strict_types=1);

namespace Commerce\Core\Update;

use RuntimeException;
use ZipArchive;

/**
 * Inspects a signed Core update bundle without loading any code from it.
 *
 * Bundle format:
 *   update.json
 *   payload.zip
 *
 * payload.zip contains only release/* paths. The signed update manifest contains the
 * SHA-256 of payload.zip, so arbitrary changes to the nested release are rejected.
 */
final class CoreUpdatePackageInspector
{
    private const MAX_BUNDLE_BYTES = 1073741824; // 1 GiB
    private const MAX_PAYLOAD_FILES = 50000;
    private const MAX_PAYLOAD_UNCOMPRESSED_BYTES = 3221225472; // 3 GiB

    public function __construct(private readonly SignedUpdateManifestVerifier $signatures)
    {
    }

    /**
     * @return array{manifest:UpdateManifest,bundle_sha256:string,payload_sha256:string,payload_bytes:int,contains_vendor:bool,release_version:string}
     */
    public function inspect(string $bundlePath, string $base64PublicKey): array
    {
        if (!is_file($bundlePath)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3f8102e0eeb8'));
        }
        $bundleSize = filesize($bundlePath);
        if (!is_int($bundleSize) || $bundleSize < 1 || $bundleSize > self::MAX_BUNDLE_BYTES) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.85dffae91cde'));
        }
        if (trim($base64PublicKey) === '') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.baa1249d92cb'));
        }
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.fc077133e0a9'));
        }

        $zip = new ZipArchive();
        if ($zip->open($bundlePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a99fde00ced0'));
        }

        $payloadTemp = null;
        try {
            $this->validateOuterBundle($zip);
            $manifestRaw = $zip->getFromName('update.json');
            if (!is_string($manifestRaw) || trim($manifestRaw) === '') {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2d40f97c7ba4'));
            }
            $data = json_decode($manifestRaw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.e8131097adef'));
            }
            $manifest = $this->manifestFromArray($data);
            $this->signatures->verify($manifest, $base64PublicKey);

            $payloadTemp = tempnam(sys_get_temp_dir(), 'mcp-update-payload-');
            if (!is_string($payloadTemp)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.bbd9ed211e19'));
            }
            $stream = $zip->getStream('payload.zip');
            if (!is_resource($stream)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.884e30c88363'));
            }
            $out = @fopen($payloadTemp, 'wb');
            if (!is_resource($out)) {
                fclose($stream);
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.e683370b77a8'));
            }
            $bytes = stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
            if (!is_int($bytes) || $bytes < 1) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b2fa3bacbafb'));
            }
            $this->signatures->verifyPackageHash($payloadTemp, $manifest->sha256);

            $payloadInfo = $this->inspectPayload($payloadTemp, $manifest->version);
            $bundleSha = hash_file('sha256', $bundlePath);
            if (!is_string($bundleSha) || strlen($bundleSha) !== 64) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ef01fcfbf6c4'));
            }

            return [
                'manifest' => $manifest,
                'bundle_sha256' => $bundleSha,
                'payload_sha256' => strtolower($manifest->sha256),
                'payload_bytes' => $bytes,
                'contains_vendor' => $payloadInfo['contains_vendor'],
                'release_version' => $payloadInfo['release_version'],
            ];
        } finally {
            $zip->close();
            if (is_string($payloadTemp)) {
                @unlink($payloadTemp);
            }
        }
    }

    /**
     * Extracts and re-verifies the signed payload into a private staging directory.
     *
     * @return array{manifest:UpdateManifest,release_dir:string,contains_vendor:bool}
     */
    public function extractRelease(string $bundlePath, string $base64PublicKey, string $stageDirectory): array
    {
        $inspection = $this->inspect($bundlePath, $base64PublicKey);
        $manifest = $inspection['manifest'];
        $this->removeTree($stageDirectory);
        if (!@mkdir($stageDirectory, 0750, true) && !is_dir($stageDirectory)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.aaa361f53323'));
        }

        $bundle = new ZipArchive();
        if ($bundle->open($bundlePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.4c5144302ce5'));
        }
        $payloadPath = $stageDirectory . '/payload.zip';
        try {
            $stream = $bundle->getStream('payload.zip');
            if (!is_resource($stream)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.884e30c88363'));
            }
            $out = @fopen($payloadPath, 'wb');
            if (!is_resource($out)) {
                fclose($stream);
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b44f153390cb'));
            }
            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
        } finally {
            $bundle->close();
        }
        $this->signatures->verifyPackageHash($payloadPath, $manifest->sha256);

        $payload = new ZipArchive();
        if ($payload->open($payloadPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3a5ea25e3bbf'));
        }
        try {
            $this->validatePayloadEntries($payload);
            for ($i = 0; $i < $payload->numFiles; $i++) {
                $name = str_replace('\\', '/', (string) $payload->getNameIndex($i));
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }
                $target = $stageDirectory . '/' . $name;
                $directory = dirname($target);
                if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3dce591898fe'));
                }
                $source = $payload->getStream($name);
                if (!is_resource($source)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0c2ea06b822c') . $name . '.');
                }
                $targetHandle = @fopen($target, 'wb');
                if (!is_resource($targetHandle)) {
                    fclose($source);
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.885beaa15fc2') . $name . '.');
                }
                stream_copy_to_stream($source, $targetHandle);
                fclose($source);
                fclose($targetHandle);
                @chmod($target, 0640);
            }
        } finally {
            $payload->close();
            @unlink($payloadPath);
        }

        $releaseDir = $stageDirectory . '/release';
        $this->validateReleaseDirectory($releaseDir, $manifest->version);

        return [
            'manifest' => $manifest,
            'release_dir' => $releaseDir,
            'contains_vendor' => is_dir($releaseDir . '/vendor'),
        ];
    }

    private function validateOuterBundle(ZipArchive $zip): void
    {
        if ($zip->numFiles < 2 || $zip->numFiles > 4) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.04ad230b5aaf'));
        }
        $allowed = ['update.json' => true, 'payload.zip' => true, 'release-notes.txt' => true, 'release-notes.md' => true];
        $seen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if (!isset($allowed[$name])) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f033f2611e94') . $name . '.');
            }
            if ($this->isSymlink($zip, $i)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.1b157ad582d5'));
            }
            $seen[$name] = true;
        }
        if (!isset($seen['update.json'], $seen['payload.zip'])) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7271fd511771'));
        }
    }

    /** @return array{contains_vendor:bool,release_version:string} */
    private function inspectPayload(string $payloadPath, string $expectedVersion): array
    {
        $zip = new ZipArchive();
        if ($zip->open($payloadPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3b7649a1e010'));
        }
        try {
            $this->validatePayloadEntries($zip);
            $releaseRaw = $zip->getFromName('release/resources/platform/release.json');
            if (!is_string($releaseRaw) || trim($releaseRaw) === '') {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f241e08c9589'));
            }
            $release = json_decode($releaseRaw, true, 32, JSON_THROW_ON_ERROR);
            $releaseVersion = is_array($release) ? (string) ($release['version'] ?? '') : '';
            if ($releaseVersion !== $expectedVersion) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.bcfe980b424b'));
            }
            foreach (['release/src/', 'release/config/', 'release/migrations/', 'release/bootstrap/', 'release/bin/', 'release/resources/', 'release/public/assets/'] as $prefix) {
                if (!$this->zipHasPrefix($zip, $prefix)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.91b6698d4ef4') . $prefix . '.');
                }
            }
            foreach (['release/composer.json', 'release/public/index.php'] as $file) {
                if ($zip->locateName($file) === false) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.91b6698d4ef4') . $file . '.');
                }
            }
            return ['contains_vendor' => $this->zipHasPrefix($zip, 'release/vendor/'), 'release_version' => $releaseVersion];
        } finally {
            $zip->close();
        }
    }

    private function validatePayloadEntries(ZipArchive $zip): void
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_PAYLOAD_FILES) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.897c37aa5c43'));
        }
        $expanded = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.bc244a8109a1'));
            }
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === '' || (!str_starts_with($name, 'release/') && !str_ends_with($name, '/'))) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d42ecc65c96e'));
            }
            if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:\//', $name) === 1 || preg_match('#(^|/)\.\.(?:/|$)#', $name) === 1 || str_contains($name, "\0")) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ba86954bee35'));
            }
            if ($this->isSymlink($zip, $i)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.eba8c40cab37'));
            }
            $expanded += max(0, (int) ($stat['size'] ?? 0));
            if ($expanded > self::MAX_PAYLOAD_UNCOMPRESSED_BYTES) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a2e379f878d0'));
            }
        }
    }

    private function validateReleaseDirectory(string $releaseDir, string $expectedVersion): void
    {
        foreach (['src', 'config', 'migrations', 'bootstrap', 'bin', 'resources', 'public/assets'] as $directory) {
            if (!is_dir($releaseDir . '/' . $directory)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9075d28d5cb4') . $directory . '.');
            }
        }
        foreach (['composer.json', 'public/index.php', 'resources/platform/release.json'] as $file) {
            if (!is_file($releaseDir . '/' . $file)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9075d28d5cb4') . $file . '.');
            }
        }
        $release = json_decode((string) file_get_contents($releaseDir . '/resources/platform/release.json'), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($release) || (string) ($release['version'] ?? '') !== $expectedVersion) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2b0c28f6aff1'));
        }
    }

    /** @param array<string,mixed> $data */
    private function manifestFromArray(array $data): UpdateManifest
    {
        foreach (['version', 'channel', 'min_php', 'max_php_exclusive', 'extension_api', 'payload_sha256', 'signature'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || trim($data[$field]) === '') {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.90d6391f4d12') . $field . '.');
            }
        }
        $requiredExtensions = [];
        foreach ((array) ($data['required_extensions'] ?? []) as $extension) {
            $extension = trim((string) $extension);
            if ($extension !== '' && preg_match('/^[A-Za-z0-9_]+$/D', $extension) === 1) {
                $requiredExtensions[] = $extension;
            }
        }

        return new UpdateManifest(
            version: trim((string) $data['version']),
            channel: trim((string) $data['channel']),
            minPhp: trim((string) $data['min_php']),
            maxPhpExclusive: trim((string) $data['max_php_exclusive']),
            extensionApi: trim((string) $data['extension_api']),
            packageUrl: 'embedded:payload.zip',
            sha256: strtolower(trim((string) $data['payload_sha256'])),
            signature: trim((string) $data['signature']),
            requiredExtensions: array_values(array_unique($requiredExtensions)),
            minDatabaseVersion: isset($data['min_database_version']) ? trim((string) $data['min_database_version']) : null,
        );
    }

    private function zipHasPrefix(ZipArchive $zip, string $prefix): bool
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with(str_replace('\\', '/', (string) $zip->getNameIndex($i)), $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function isSymlink(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attributes = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes) || $opsys !== ZipArchive::OPSYS_UNIX) {
            return false;
        }
        return (($attributes >> 16) & 0170000) === 0120000;
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            @unlink($directory);
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
