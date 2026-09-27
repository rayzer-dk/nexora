<?php

declare(strict_types=1);

namespace Commerce\Core\Module;

interface ModuleInterface
{
    public function manifest(): ModuleManifest;
}
