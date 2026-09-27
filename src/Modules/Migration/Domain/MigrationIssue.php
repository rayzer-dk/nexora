<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Domain;

final readonly class MigrationIssue
{
    public function __construct(
        public string $severity,
        public string $code,
        public string $message,
        public ?MigrationEntityType $entityType = null,
        public ?string $sourceKey = null,
    ) {
    }
}
