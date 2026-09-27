#!/usr/bin/env php
<?php

declare(strict_types=1);

$rootProject = dirname(__DIR__);
foreach (['FailurePoint','FailureInjector','ImmutableReleaseLayout','AtomicReleaseLinkSwitcher','ImmutableDeploymentExecutor'] as $class) {
    require_once $rootProject . '/src/Core/Deployment/' . $class . '.php';
}
use Commerce\Core\Deployment\FailureInjector;
use Commerce\Core\Deployment\FailurePoint;
use Commerce\Core\Deployment\ImmutableDeploymentExecutor;
use Commerce\Core\Deployment\ImmutableReleaseLayout;

$failures = [];
foreach (FailurePoint::cases() as $point) {
    $root = sys_get_temp_dir() . '/mc-fi-' . bin2hex(random_bytes(6));
    mkdir($root . '/releases/old/public', 0750, true);
    file_put_contents($root . '/releases/old/public/index.php', 'old');
    symlink($root . '/releases/old', $root . '/current');
    $executor = new ImmutableDeploymentExecutor(new ImmutableReleaseLayout($root), new FailureInjector($point));
    try {
        $executor->deploy('new', static function (string $dir): void {
            mkdir($dir . '/public', 0750, true);
            file_put_contents($dir . '/public/index.php', 'new');
        }, static function (): void {}, static fn (): bool => true);
    } catch (Throwable) {
    }
    $actual = is_link($root . '/current') ? readlink($root . '/current') : null;
    if ($actual !== $root . '/releases/old') {
        $failures[] = $point->value;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) { $file->isLink() || $file->isFile() ? @unlink($file->getPathname()) : @rmdir($file->getPathname()); }
    @rmdir($root);
}
if ($failures !== []) { fwrite(STDERR, 'FAILED: ' . implode(', ', $failures) . PHP_EOL); exit(1); }
echo 'Failure-injection rollback checks passed: ' . count(FailurePoint::cases()) . PHP_EOL;
