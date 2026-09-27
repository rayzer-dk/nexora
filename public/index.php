<?php

declare(strict_types=1);

use Commerce\Kernel;

$projectDir = dirname(__DIR__);
$installedLock = $projectDir . '/var/install/installed.lock';
if (!is_file($installedLock) && PHP_SAPI !== 'cli') {
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/.');
    header('Location: ' . ($base !== '' ? $base : '') . '/setup.php', true, 302);
    exit;
}

require_once $projectDir . '/bootstrap/emergency.php';
require_once $projectDir . '/vendor/autoload_runtime.php';

return static function (array $context): Kernel {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
