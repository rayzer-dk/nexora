<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Builder;

use RuntimeException;

/** The layout was saved by someone (or another tab) after the version the editor started from. */
final class LayoutConflictException extends RuntimeException
{
    public function __construct(public readonly int $currentVersion, public readonly ?int $savedBy, public readonly string $savedAt)
    {
        parent::__construct('Layout version ' . $currentVersion . ' is newer than the edited one.');
    }
}
