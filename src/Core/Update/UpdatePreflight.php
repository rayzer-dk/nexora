<?php

declare(strict_types=1);

namespace Commerce\Core\Update;

use Commerce\Core\Platform\PlatformVersion;

final class UpdatePreflight
{
    public function validate(UpdateManifest $manifest): array
    {
        $errors = [];

        if (version_compare(PHP_VERSION, $manifest->minPhp, '<')) {
            $errors[] = sprintf('PHP %s or newer is required; installed %s.', $manifest->minPhp, PHP_VERSION);
        }

        if (version_compare(PHP_VERSION, $manifest->maxPhpExclusive, '>=')) {
            $errors[] = sprintf(
                'PHP %s is not certified by this update; supported range is >=%s <%s.',
                PHP_VERSION,
                $manifest->minPhp,
                $manifest->maxPhpExclusive,
            );
        }

        foreach ($manifest->requiredExtensions as $extension) {
            if (!extension_loaded((string) $extension)) {
                $errors[] = sprintf('Required PHP extension %s is not loaded.', $extension);
            }
        }

        if ($manifest->extensionApi !== PlatformVersion::EXTENSION_API) {
            $errors[] = sprintf(
                'Extension API mismatch: update requires %s, platform provides %s.',
                $manifest->extensionApi,
                PlatformVersion::EXTENSION_API,
            );
        }

        return $errors;
    }
}
