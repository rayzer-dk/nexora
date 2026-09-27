<?php

declare(strict_types=1);

namespace Commerce\Core\Runtime;

final class DeferredWorkSignal
{
    private bool $pending = false;

    public function mark(): void
    {
        $this->pending = true;
    }

    public function pending(): bool
    {
        return $this->pending;
    }

    public function clear(): void
    {
        $this->pending = false;
    }
}
