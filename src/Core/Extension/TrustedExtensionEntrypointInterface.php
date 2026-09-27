<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

interface TrustedExtensionEntrypointInterface
{
    public function boot(TrustedExtensionContext $context): void;
}
