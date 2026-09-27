<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Spam;

final readonly class SpamCheckResult
{
    public function __construct(
        public bool $allowed,
        public string $reason = 'ok',
        public bool $requiresChallenge = false,
    ) {
    }
}
