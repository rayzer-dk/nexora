<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Contract;

use Commerce\Modules\Migration\Domain\MigrationBatch;
use Commerce\Modules\Migration\Domain\MigrationEntityType;

interface MigrationSourceInterface
{
    public function code(): string;
    public function label(): string;

    /** @return list<MigrationEntityType> */
    public function supportedEntities(): array;

    public function read(MigrationEntityType $type, ?string $cursor, int $limit = 250): MigrationBatch;
}
