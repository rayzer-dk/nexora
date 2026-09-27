<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Domain;

final readonly class MigrationRecord
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public MigrationEntityType $type,
        public string $sourceKey,
        public array $data,
    ) {
    }
}
