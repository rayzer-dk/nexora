<?php

declare(strict_types=1);

namespace Commerce\Modules\Integration\Application;

final readonly class IntegrationRetryPolicy
{
    public function __construct(private int $maxAttempts = 8, private int $baseDelaySeconds = 30, private int $maxDelaySeconds = 3600) {}

    /** @return array{dead:bool,delay_seconds:int} */
    public function afterFailure(int $attempts): array
    {
        $attempts=max(1,$attempts);
        $dead=$attempts >= max(1,$this->maxAttempts);
        $delay=$dead ? 0 : min(max(1,$this->maxDelaySeconds), max(1,$this->baseDelaySeconds) * (2 ** min(7,max(0,$attempts-1))));
        return ['dead'=>$dead,'delay_seconds'=>$delay];
    }
}
