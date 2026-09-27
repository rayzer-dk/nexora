<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Domain;

use InvalidArgumentException;

final readonly class TransferPackageManifest
{
    /** @param array<string, string> $files */
    public function __construct(
        public string $format,
        public int $version,
        public string $sourceSystem,
        public array $files,
    ) {
        if ($format !== 'nexora-commerce-transfer') {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.82a5d2ae47d1'));
        }
        if ($version !== 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7dd0130cb84a'));
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['format'] ?? ''),
            (int) ($data['version'] ?? 0),
            (string) ($data['source_system'] ?? 'unknown'),
            array_map('strval', (array) ($data['files'] ?? [])),
        );
    }
}
