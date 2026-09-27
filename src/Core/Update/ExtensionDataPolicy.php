<?php

declare(strict_types=1);

namespace Commerce\Core\Update;

enum ExtensionDataPolicy: string
{
    /** Disable/remove executable code but retain extension data for safe reinstallation and history. */
    case RetainOnUninstall = 'retain_on_uninstall';

    /** Purge only after a separate explicit destructive action. */
    case ExplicitPurgeOnly = 'explicit_purge_only';
}
