<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit\Support;

use Commerce\Core\Extension\ExtensionConflictGuard;
use Commerce\Core\Extension\ExtensionMigrationRunner;
use Commerce\Core\Extension\ExtensionPackageManager;
use Commerce\Core\Extension\ExtensionPackageValidator;
use Commerce\Core\Extension\ExtensionSettingsManager;
use Commerce\Core\Id\PublicIdFactory;
use Doctrine\DBAL\Connection;

/**
 * An ExtensionPackageManager for tests of what happens to modules that are already installed (activate, lifecycle,
 * diagnostics). install() is the only user of the package validator and the conflict guard, so they are left empty.
 */
final class ExtensionPackageManagerFactory
{
    public static function forInstalledModules(Connection $db, ExtensionMigrationRunner $runner, string $directory, ?ExtensionSettingsManager $settings = null): ExtensionPackageManager
    {
        return new ExtensionPackageManager(
            $db,
            new PublicIdFactory(),
            (new \ReflectionClass(ExtensionPackageValidator::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(ExtensionConflictGuard::class))->newInstanceWithoutConstructor(),
            $runner,
            $directory,
            $settings,
        );
    }
}
