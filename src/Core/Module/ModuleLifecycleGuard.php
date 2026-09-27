<?php

declare(strict_types=1);

namespace Commerce\Core\Module;

use DomainException;

final class ModuleLifecycleGuard
{
    /** @var array<string,SystemModuleDefinition> */
    private array $catalog;

    /** @param array<string,SystemModuleDefinition>|null $catalog */
    public function __construct(?array $catalog = null)
    {
        $this->catalog = $catalog ?? SystemModuleCatalog::all();
    }

    public function canUninstall(string $moduleCode): bool
    {
        return !isset($this->catalog[$moduleCode]) || $this->catalog[$moduleCode]->removable();
    }

    public function assertCanUninstall(string $moduleCode): void
    {
        if (!$this->canUninstall($moduleCode)) {
            throw new DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.39acb882eac1') . $moduleCode);
        }
    }

    public function isProtected(string $moduleCode): bool
    {
        return isset($this->catalog[$moduleCode])
            && $this->catalog[$moduleCode]->removalPolicy === ModuleRemovalPolicy::Protected;
    }
}
