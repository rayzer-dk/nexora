<?php

declare(strict_types=1);

namespace Commerce\Modules\Integration\Contract;

final readonly class ConnectorHealth
{
    public function __construct(
        public bool $reachable,
        public ?string $message = null,
        public ?\DateTimeImmutable $checkedAt = null,
    ) {
    }
}
