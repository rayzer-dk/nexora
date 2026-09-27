<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Application;

final readonly class MigrationImportResult
{
    /** @param array<string,int> $counts */
    public function __construct(
        public string $runId,
        public string $status,
        public array $counts,
        public int $issues,
    ) {}
}
