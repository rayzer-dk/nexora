<?php

declare(strict_types=1);

namespace Commerce\Core\Update;

final readonly class ExtensionCompatibilityGuard
{
    public function isCompatible(CompatibilityContract $contract): bool
    {
        if (!$contract->supportsRuntime(PHP_VERSION)) {
            return false;
        }

        if (version_compare(PlatformContractVersion::EXTENSION_API, $contract->coreApi, '<')) {
            return false;
        }

        if ($contract->maximumCoreApi !== null
            && version_compare(PlatformContractVersion::EXTENSION_API, $contract->maximumCoreApi, '>')) {
            return false;
        }

        return true;
    }
}
