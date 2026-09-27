<?php

declare(strict_types=1);

namespace Commerce\Core\Module;

final readonly class SystemModuleDefinition
{
    /** @param list<string> $dependencies */
    public function __construct(
        public string $code,
        public string $name,
        public ModuleTier $tier,
        public ModuleRemovalPolicy $removalPolicy,
        public array $dependencies = [],
        public bool $enabledByDefault = true,
        public ModuleMaturity $maturity = ModuleMaturity::Stable,
    ) {
    }

    public function removable(): bool
    {
        return $this->removalPolicy === ModuleRemovalPolicy::Removable;
    }
}
