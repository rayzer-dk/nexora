<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Source\Package;

use Commerce\Modules\Migration\Contract\MigrationSourceInterface;
use Commerce\Modules\Migration\Domain\MigrationBatch;
use Commerce\Modules\Migration\Domain\MigrationEntityType;
use Commerce\Modules\Migration\Domain\MigrationRecord;
use Commerce\Modules\Migration\Domain\TransferPackageManifest;
use JsonException;
use RuntimeException;

final class TransferPackageDirectorySource implements MigrationSourceInterface
{
    private readonly TransferPackageManifest $manifest;

    public function __construct(private readonly string $directory)
    {
        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.75ced1980de5'));
        }
        $manifestPath = $root . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!is_file($manifestPath)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9345fe8a36f7'));
        }
        try {
            $data = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.26873383a2ea'), 0, $e);
        }
        $this->manifest = TransferPackageManifest::fromArray((array) $data);
    }

    public function code(): string
    {
        return 'transfer-package-v1';
    }

    public function label(): string
    {
        return sprintf('Transfer package from %s', $this->manifest->sourceSystem);
    }

    public function supportedEntities(): array
    {
        $types = [];
        foreach (array_keys($this->manifest->files) as $name) {
            $type = MigrationEntityType::tryFrom($name);
            if ($type !== null) {
                $types[] = $type;
            }
        }
        return $types;
    }

    public function read(MigrationEntityType $type, ?string $cursor, int $limit = 250): MigrationBatch
    {
        $relative = $this->manifest->files[$type->value] ?? null;
        if ($relative === null) {
            return new MigrationBatch([], null, true);
        }
        if (!$this->isSafeRelativePath($relative)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2aacc334bc17'));
        }
        $path = realpath($this->directory . DIRECTORY_SEPARATOR . $relative);
        $root = realpath($this->directory);
        if ($path === false || $root === false || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.e36acb752fa7'));
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.af16ae0a9a9e'));
        }
        $offset = max(0, (int) ($cursor ?? '0'));
        if ($offset > 0 && fseek($handle, $offset) !== 0) {
            fclose($handle);
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d93c6b1ccdf0'));
        }

        $records = [];
        try {
            while (count($records) < max(1, min(1000, $limit)) && ($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                $sourceKey = trim((string) ($row['source_key'] ?? ''));
                if ($sourceKey === '') {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.67d0a2185041'));
                }
                $records[] = new MigrationRecord($type, $sourceKey, (array) ($row['data'] ?? []));
            }
            $nextOffset = ftell($handle);
            $complete = feof($handle);
        } catch (JsonException $e) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.094e05c10eec'), 0, $e);
        } finally {
            fclose($handle);
        }

        return new MigrationBatch($records, $complete ? null : (string) $nextOffset, $complete);
    }

    private function isSafeRelativePath(string $path): bool
    {
        return $path !== ''
            && !str_starts_with($path, '/')
            && !str_contains($path, '..')
            && !str_contains($path, "\\")
            && preg_match('/^[A-Za-z0-9._\/-]+$/', $path) === 1;
    }
}
