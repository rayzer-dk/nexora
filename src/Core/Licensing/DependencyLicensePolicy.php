<?php

declare(strict_types=1);

namespace Commerce\Core\Licensing;

final class DependencyLicensePolicy
{
    /** @var list<string> */
    private const ALLOWED = [
        'MIT', 'BSD-2-Clause', 'BSD-3-Clause', 'Apache-2.0', 'ISC',
        'MIT-0', '0BSD', 'Unlicense', 'CC0-1.0', 'BlueOak-1.0.0', 'Python-2.0',
        'MPL-2.0', 'LGPL-2.1', 'LGPL-2.1-only', 'LGPL-2.1-or-later', 'LGPL-3.0-only', 'LGPL-3.0-or-later',
    ];

    public function isAllowed(string $license): bool
    {
        $license = trim($license);
        if ($license === '') {
            return false;
        }
        foreach (preg_split('/\s+(?:OR|AND)\s+/i', $license) ?: [] as $part) {
            if (!in_array(trim($part, "() \t\n\r\0\x0B"), self::ALLOWED, true)) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> */
    public function allowedLicenses(): array
    {
        return self::ALLOWED;
    }
}
