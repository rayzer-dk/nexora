<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit\Support;

use Commerce\Modules\Media\Application\MediaVariantService;
use Commerce\Modules\Media\Application\VipsImageEncoder;

/** Builds a MediaVariantService on a folder and a fixed image profile, without a database or a settings revision store. */
final class MediaVariantServiceFactory
{
    /** @param array<string,mixed> $profile */
    public static function make(string $projectDir, array $profile, ?VipsImageEncoder $vips = null): MediaVariantService
    {
        $service = (new \ReflectionClass(MediaVariantService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($service, 'projectDir'))->setValue($service, $projectDir);
        (new \ReflectionProperty($service, 'profile'))->setValue($service, $profile);
        if ($vips !== null) {
            (new \ReflectionProperty($service, 'vips'))->setValue($service, $vips);
        }

        return $service;
    }
}
