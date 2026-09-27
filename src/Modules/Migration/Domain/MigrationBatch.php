<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Domain;

final readonly class MigrationBatch
{
    /** @param list<MigrationRecord> $records */
    public function __construct(
        public array $records,
        public ?string $nextCursor,
        public bool $complete,
    ) {
    }
}
