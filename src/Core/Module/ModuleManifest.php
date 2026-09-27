<?php

declare(strict_types=1);

namespace Commerce\Core\Module;

final readonly class ModuleManifest
{
    public function __construct(
        public string $code,
        public string $name,
        public string $version,
        public string $apiVersion,
        public array $capabilities = [],
        public array $permissions = [],
        public array $dependencies = [],
    ) {
    }
}
