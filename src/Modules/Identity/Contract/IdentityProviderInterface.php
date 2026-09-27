<?php

declare(strict_types=1);

namespace Commerce\Modules\Identity\Contract;

interface IdentityProviderInterface
{
    public function code(): string;

    public function verify(string $credential): ExternalIdentity;
}
