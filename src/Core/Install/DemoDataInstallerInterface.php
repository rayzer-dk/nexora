<?php

declare(strict_types=1);

namespace Commerce\Core\Install;

/** Optional: present only while the Demo module is installed. */
interface DemoDataInstallerInterface
{
    /** @return array<string,mixed> */
    public function install(): array;
}
