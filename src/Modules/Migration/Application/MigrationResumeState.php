<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Application;

final readonly class MigrationResumeState
{
    public function __construct(
        public string $publicId,
        public string $sourceCode,
        public string $status,
        public ?string $entityType,
        public ?string $cursor,
        public int $processedCount,
        public int $issueCount,
    ) {
    }
}
