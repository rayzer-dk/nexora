<?php

declare(strict_types=1);

/**
 * Shared hosting often enables open_basedir for the account directory only, so the system temporary
 * directory (/tmp) is not reachable and every component that uses sys_get_temp_dir() (lock store, uploads,
 * import, update inspector) fails with a 500. When the system directory is not usable, point PHP to
 * var/tmp inside the project. Must run before the first sys_get_temp_dir() call of the process.
 */
(static function (): void {
    // Do not call sys_get_temp_dir() here: PHP caches its first answer for the whole process.
    $system = (string) (getenv('TMPDIR') ?: '/tmp');
    if (@is_dir($system) && @is_writable($system)) {
        return;
    }
    $dir = dirname(__DIR__) . '/var/tmp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        putenv('TMPDIR=' . $dir);
        $_SERVER['TMPDIR'] = $_ENV['TMPDIR'] = $dir;
    }
})();
