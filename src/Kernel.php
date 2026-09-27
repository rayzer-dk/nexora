<?php

declare(strict_types=1);

namespace Commerce;

use Commerce\Core\Platform\PlatformVersion;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/' . $this->environment . '-' . PlatformVersion::VERSION;
    }

    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }
}
