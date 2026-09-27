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

if (!is_file($projectDir . '/.env')) {
    $runtimeOptions = is_array($_SERVER['APP_RUNTIME_OPTIONS'] ?? null)
        ? $_SERVER['APP_RUNTIME_OPTIONS']
        : [];

    if (is_file($projectDir . '/.env.local')) {
        $runtimeOptions['dotenv_path'] = '.env.local';
        $runtimeOptions['dotenv_use_putenv'] = true;
    } else {
        // Support immutable/container deployments where configuration is
        // injected exclusively through environment variables.
        $runtimeOptions['dotenv_path'] = false;
    }

    $_SERVER['APP_RUNTIME_OPTIONS'] = $runtimeOptions;
}

require_once $projectDir . '/vendor/autoload_runtime.php';

return static function (array $context): Kernel {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
