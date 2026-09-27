<?php

declare(strict_types=1);

namespace Commerce\Core\Deployment;

enum FailurePoint: string
{
    case AfterBackup = 'after_backup';
    case AfterReleasePrepare = 'after_release_prepare';
    case BeforeMigration = 'before_migration';
    case AfterMigration = 'after_migration';
    case BeforeSwitch = 'before_switch';
    case AfterSwitch = 'after_switch';
    case SmokeProbe = 'smoke_probe';
}
