<?php

declare(strict_types=1);

namespace Commerce\Core\Runtime;

/** A durable queue a module drains in the bounded worker pass (`commerce:work`) and after a request that created deferred work. */
interface DeferredWorkerInterface
{
    /** Short queue name printed by `commerce:work`, e.g. `notifications`. */
    public function queue(): string;

    /** @return array<string,int> counters of the pass, printed in the given order */
    public function run(int $limit): array;
}
