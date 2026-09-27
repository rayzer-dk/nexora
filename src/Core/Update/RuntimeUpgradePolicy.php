<?php

declare(strict_types=1);

namespace Commerce\Core\Update;

final readonly class RuntimeUpgradePolicy
{
    public function __construct(
        public string $currentPhpBaseline = '8.5',
        public bool $extensionsMayDependOnCoreInternals = false,
        public bool $coreUpdateRequiresPreflight = true,
        public bool $coreUpdateRequiresRollbackPoint = true,
        public bool $extensionApiIsVersioned = true,
    ) {
    }

    public function protectsExtensionsFromInternalFrameworkUpgrade(): bool
    {
        return !$this->extensionsMayDependOnCoreInternals && $this->extensionApiIsVersioned;
    }
}
