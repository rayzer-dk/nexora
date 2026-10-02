<?php

declare(strict_types=1);

use Commerce\Kernel;

$projectDir = dirname(__DIR__);
$installedLock = $projectDir . '/var/install/installed.lock';
$requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$installHandover = in_array($requestPath, ['/install', '/install/finish'], true);
if (!is_file($installedLock) && PHP_SAPI !== 'cli' && !$installHandover) {
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/.');
    header('Location: ' . ($base !== '' ? $base : '') . '/setup.php', true, 302);
    exit;
}

require_once $projectDir . '/bootstrap/tmpdir.php';
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
        $runtimeOptions['disable_dotenv'] = true;
    }

    $_SERVER['APP_RUNTIME_OPTIONS'] = $runtimeOptions;
}

// With variables_order="GPCS" (the php.ini-production default) the PHP built-in server and some other SAPIs keep the process
// environment out of $_SERVER. Symfony Runtime would then silently fall back to APP_ENV=dev with debug on, ignoring APP_ENV=prod
// and APP_DEBUG=0 set for the process. Take the two switches from the real environment first.
foreach (['APP_ENV', 'APP_DEBUG'] as $switch) {
    if (!isset($_SERVER[$switch]) && !isset($_ENV[$switch]) && ($value = getenv($switch)) !== false) {
        $_SERVER[$switch] = $_ENV[$switch] = $value;
    }
}

require_once $projectDir . '/vendor/autoload_runtime.php';

return static function (array $context): Kernel {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
