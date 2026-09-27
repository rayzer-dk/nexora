<?php

declare(strict_types=1);

namespace Commerce\Modules\Identity\Contract;

final readonly class ExternalIdentity
{
    public function __construct(
        public string $provider,
        public string $subject,
        public ?string $email,
        public ?string $displayName,
        public bool $emailVerified,
    ) {
    }
}
